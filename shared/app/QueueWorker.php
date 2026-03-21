<?php

declare(strict_types=1);

namespace App;

final class QueueWorker
{
    private Runtime $runtime;
    private Repository $repository;
    private Config $config;
    private FfmpegCommandBuilder $ffmpegCommandBuilder;
    private ArchiveCommandBuilder $archiveCommandBuilder;
    private LoudnormAnalyzer $loudnormAnalyzer;

    private bool $shutdownRequested = false;

    /**
     * @var resource|null
     */
    private $process = null;

    /**
     * @var array<int, resource>
     */
    private array $pipes = [];

    private ?array $activeJob = null;
    private ?array $activeCommand = null;
    private ?int $activePid = null;
    private bool $activePaused = false;
    private ?int $cancelSentAt = null;
    private string $stderrBuffer = '';
    private string $stdoutBuffer = '';
    private array $progressPacket = [];
    private array $stderrTail = [];
    private float $lastProgressPercent = -1.0;
    private int $lastProgressEventAt = 0;
    private ?string $lastReportSignature = null;
    private int $lastReportAt = 0;
    private array $backgroundAnalysisTasks = [];

    public function __construct(Runtime $runtime)
    {
        $this->runtime = $runtime;
        $this->repository = $runtime->repository();
        $this->config = $runtime->config();
        $this->ffmpegCommandBuilder = new FfmpegCommandBuilder($runtime->config(), $runtime->paths());
        $this->archiveCommandBuilder = new ArchiveCommandBuilder($runtime->config(), $runtime->paths());
        $this->loudnormAnalyzer = new LoudnormAnalyzer($runtime->config(), $runtime->paths());
    }

    public function run(bool $singleShot = false): void
    {
        $this->installSignalHandlers();
        $this->repository->recoverInterruptedJobs();
        $this->reportState('idle', ['message' => 'Worker booted']);

        $processedAny = false;
        while (!$this->shutdownRequested) {
            $this->tickBackgroundAnalysisTasks();

            if ($this->process !== null) {
                $this->tickActiveProcess();
                if ($singleShot && $this->process === null && !$this->hasBackgroundAnalysisTasks() && $processedAny) {
                    break;
                }
                continue;
            }

            if ($this->hasBackgroundAnalysisTasks()) {
                usleep($this->config->workerPollIntervalMs() * 1000);
                continue;
            }

            $job = $this->repository->acquireNextRunnableJob(getmypid());
            if ($job === null) {
                if ($singleShot) {
                    break;
                }

                $this->reportState('idle', ['message' => 'Queue empty']);
                usleep($this->config->workerIdleSleepMs() * 1000);
                continue;
            }

            $processedAny = true;
            $this->startJob($job);
        }

        if ($this->process !== null && $this->activeJob !== null) {
            $this->terminateProcessGroup(SIGTERM);
            $this->repository->markJobCancelled($this->activeJob['id'], [
                'command' => $this->activeCommand['command'] ?? [],
                'output_path' => $this->activeCommand['output_path'] ?? null,
                'stderr_tail' => $this->stderrTail,
                'message' => 'Worker shutdown requested',
            ]);
            $this->cleanupActiveProcess();
        }

        $this->terminateBackgroundAnalysisTasks();
        $this->reportState('stopped', ['message' => 'Worker stopped']);
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->shutdownRequested = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->shutdownRequested = true;
        });
    }

    private function startJob(array $job): void
    {
        $this->activeJob = $job;
        $this->activeCommand = null;
        $this->activePid = null;
        $this->activePaused = false;
        $this->cancelSentAt = null;
        $this->stderrBuffer = '';
        $this->stdoutBuffer = '';
        $this->progressPacket = [];
        $this->stderrTail = [];
        $this->lastProgressPercent = -1.0;
        $this->lastProgressEventAt = 0;

        try {
            $job = $this->prepareJob($job);
            $this->activeJob = $job;
            $command = $this->buildCommand($job);
            $this->activeCommand = $command;

            $job = $this->repository->updateJobStatus($job['id'], 'running', 'Job starting', [
                'output_folder' => $command['output']['folder'],
                'output_file' => $command['output']['file_name'],
                'result' => [
                    'command' => $command['command'],
                    'output_path' => $command['output_path'],
                    'source_path' => $command['source_path'],
                    'started_by_worker_pid' => getmypid(),
                ],
            ]);
            $this->activeJob = $job;

            $descriptorSpec = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                array_merge(['/usr/bin/setsid'], $command['command']),
                $descriptorSpec,
                $pipes,
                APP_ROOT,
                null,
                ['bypass_shell' => true]
            );

            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to start worker process');
            }

            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }

            $status = proc_get_status($process);
            $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
            if ($pid <= 0) {
                proc_close($process);
                throw new \RuntimeException('Unable to determine worker pid');
            }

            $this->process = $process;
            $this->pipes = $pipes;
            $this->activePid = $pid;
            $this->activeJob = $this->repository->markJobStarted($job['id'], $pid, [
                'command' => $command['command'],
                'output_path' => $command['output_path'],
                'source_path' => $command['source_path'],
                'kind' => $command['kind'] ?? $job['type'] ?? 'video',
            ]);

            $this->reportState('running', [
                'job_id' => $job['id'],
                'output_path' => $command['output_path'],
            ]);
        } catch (\Throwable $throwable) {
            $currentJob = $this->repository->getJob($job['id']) ?? $job;
            if (in_array(($currentJob['status'] ?? ''), ['cancel_requested', 'cancelled'], true)) {
                $this->repository->markJobCancelled($job['id'], [
                    'command' => $this->activeCommand['command'] ?? [],
                    'output_path' => $this->activeCommand['output_path'] ?? null,
                    'stderr_tail' => $this->stderrTail,
                    'message' => $throwable->getMessage(),
                ]);
                $this->reportState('cancelled', [
                    'job_id' => $job['id'],
                    'message' => $throwable->getMessage(),
                ]);
                $this->cleanupActiveProcess();
                return;
            }

            $this->repository->markJobFailed($job['id'], $throwable->getMessage(), [
                'command' => $this->activeCommand['command'] ?? [],
                'output_path' => $this->activeCommand['output_path'] ?? null,
            ]);
            $this->reportState('failed', [
                'job_id' => $job['id'],
                'message' => $throwable->getMessage(),
            ]);
            $this->cleanupActiveProcess();
        }
    }

    private function tickActiveProcess(): void
    {
        if ($this->process === null || $this->activeJob === null) {
            return;
        }

        $this->drainProcessPipes();
        $job = $this->repository->getJob($this->activeJob['id']);
        if ($job === null) {
            $this->terminateProcessGroup(SIGTERM);
            $this->cleanupActiveProcess();
            return;
        }

        $this->activeJob = $job;
        $this->syncControlState($job);
        $this->repository->touchWorkerHeartbeat($job['id'], $this->activePid);
        $this->tickGenericProgress();

        $status = proc_get_status($this->process);
        if (!($status['running'] ?? false)) {
            $this->finalizeProcess((int) ($status['exitcode'] ?? -1));
            return;
        }

        $this->dispatchBackgroundAnalysisTasks();
        usleep($this->config->workerPollIntervalMs() * 1000);
    }

    private function syncControlState(array $job): void
    {
        if ($job['status'] === 'cancel_requested') {
            if ($this->cancelSentAt === null) {
                $this->terminateProcessGroup(SIGTERM);
                $this->cancelSentAt = time();
                $this->reportState('cancel_requested', ['job_id' => $job['id']]);
                $this->repository->appendEvent($job['id'], 'control', [
                    'message' => 'SIGTERM sent to worker process group',
                    'signal' => 'SIGTERM',
                ]);
                return;
            }

            if ((time() - $this->cancelSentAt) >= $this->config->workerCancelGraceSeconds()) {
                $this->terminateProcessGroup(SIGKILL);
                $this->repository->appendEvent($job['id'], 'control', [
                    'message' => 'SIGKILL sent after cancel grace timeout',
                    'signal' => 'SIGKILL',
                ]);
            }

            return;
        }

        if ($job['status'] === 'paused' && !$this->activePaused) {
            $this->terminateProcessGroup(SIGSTOP);
            $this->activePaused = true;
            $this->reportState('paused', ['job_id' => $job['id']]);
            $this->repository->updateJobProgress($job['id'], [
                'phase' => 'paused',
                'percent' => $job['progress']['percent'] ?? null,
                'speed' => null,
                'eta_seconds' => null,
            ], true);
            return;
        }

        if ($job['status'] === 'queued' && $this->activePaused) {
            $this->terminateProcessGroup(SIGCONT);
            $this->activePaused = false;
            $this->activeJob = $this->repository->updateJobStatus($job['id'], 'running', 'Job resumed');
            $this->reportState('running', ['job_id' => $job['id'], 'message' => 'Job resumed']);
        }
    }

    private function finalizeProcess(int $exitCode): void
    {
        if ($this->activeJob === null) {
            $this->cleanupActiveProcess();
            return;
        }

        $this->drainProcessPipes();
        $job = $this->repository->getJob($this->activeJob['id']) ?? $this->activeJob;
        if ($exitCode < 0 && is_resource($this->process)) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = [];
            $exitCode = proc_close($this->process);
            $this->process = null;
        }

        $result = [
            'command' => $this->activeCommand['command'] ?? [],
            'output_path' => $this->activeCommand['output_path'] ?? null,
            'exit_code' => $exitCode,
            'stderr_tail' => $this->stderrTail,
            'finished_at' => gmdate('c'),
        ];

        if (in_array($job['status'], ['cancel_requested', 'cancelled'], true) || $this->shutdownRequested) {
            $this->repository->markJobCancelled($job['id'], $result);
            $this->reportState('cancelled', ['job_id' => $job['id']]);
            $this->cleanupActiveProcess();
            return;
        }

        if ($exitCode === 0) {
            $result = $this->updateCompletedOutputMetadata($job, $result);
            $this->repository->markJobCompleted($job['id'], $result);
            $this->reportState('completed', ['job_id' => $job['id']]);
            $this->cleanupActiveProcess();
            return;
        }

        $message = ($this->activeCommand['kind'] ?? null) === 'archive'
            ? 'Archive command exited with code ' . $exitCode
            : 'FFmpeg exited with code ' . $exitCode;
        $this->repository->markJobFailed($job['id'], $message, $result);
        $this->reportState('failed', [
            'job_id' => $job['id'],
            'message' => $message,
        ]);
        $this->cleanupActiveProcess();
    }

    private function drainProcessPipes(): void
    {
        if ($this->process === null) {
            return;
        }

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            $chunk = stream_get_contents($this->pipes[1]);
            if (is_string($chunk) && $chunk !== '') {
                $this->stdoutBuffer .= $chunk;
                $this->collectStderrTail($chunk);
            }
        }

        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $chunk = stream_get_contents($this->pipes[2]);
            if (is_string($chunk) && $chunk !== '') {
                $this->stderrBuffer .= $chunk;
                $this->collectStderrTail($chunk);
                if (($this->activeCommand['kind'] ?? 'ffmpeg') === 'ffmpeg') {
                    $this->parseProgressBuffer();
                }
            }
        }
    }

    private function tickGenericProgress(): void
    {
        if ($this->activeJob === null || $this->activeCommand === null) {
            return;
        }

        if (($this->activeCommand['kind'] ?? 'ffmpeg') === 'ffmpeg') {
            return;
        }

        $progress = $this->activeJob['progress'] ?? [];
        $phase = $this->activePaused ? 'paused' : 'extracting';
        $message = $this->stderrTail !== [] ? (string) end($this->stderrTail) : 'Archive extracting';
        $emitEvent = (time() - $this->lastProgressEventAt) >= 5 || (($progress['phase'] ?? null) !== $phase);

        $this->repository->updateJobProgress($this->activeJob['id'], [
            'phase' => $phase,
            'percent' => null,
            'speed' => null,
            'eta_seconds' => null,
            'message' => $message,
        ], $emitEvent);

        if ($emitEvent) {
            $this->lastProgressEventAt = time();
        }
    }

    private function parseProgressBuffer(): void
    {
        while (($position = strpos($this->stderrBuffer, "\n")) !== false) {
            $line = trim(substr($this->stderrBuffer, 0, $position));
            $this->stderrBuffer = (string) substr($this->stderrBuffer, $position + 1);

            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $this->progressPacket[$key] = $value;

            if ($key === 'progress') {
                $this->emitProgressPacket($this->progressPacket);
                $this->progressPacket = [];
            }
        }
    }

    private function emitProgressPacket(array $packet): void
    {
        if ($this->activeJob === null) {
            return;
        }

        $duration = (float) ($this->activeJob['analysis']['info']['duration_seconds'] ?? 0.0);
        $processedSeconds = $this->extractProcessedSeconds($packet);
        $progressValue = isset($packet['progress']) ? (string) $packet['progress'] : 'continue';
        $percent = null;
        if ($duration > 0 && $processedSeconds !== null) {
            $percent = min(100.0, round(($processedSeconds / $duration) * 100, 2));
        }
        if ($progressValue === 'end') {
            $percent = 100.0;
        }

        $speed = $this->extractSpeed($packet);
        $etaSeconds = null;
        if ($duration > 0 && $processedSeconds !== null && $speed !== null && $speed > 0) {
            $etaSeconds = max(0, (int) round(($duration - $processedSeconds) / $speed));
        }

        $progress = [
            'phase' => $this->activePaused ? 'paused' : 'running',
            'percent' => $percent,
            'processed_seconds' => $processedSeconds,
            'duration_seconds' => $duration > 0 ? $duration : null,
            'speed' => isset($packet['speed']) ? (string) $packet['speed'] : null,
            'eta_seconds' => $etaSeconds,
            'frame' => isset($packet['frame']) ? (int) $packet['frame'] : null,
            'fps' => isset($packet['fps']) && is_numeric($packet['fps']) ? (float) $packet['fps'] : null,
            'bitrate' => $packet['bitrate'] ?? null,
        ];

        $emitEvent = false;
        $now = time();
        if ($progressValue === 'end') {
            $emitEvent = true;
        } elseif ($percent !== null && ($this->lastProgressPercent < 0 || ($percent - $this->lastProgressPercent) >= 5)) {
            $emitEvent = true;
        } elseif (($now - $this->lastProgressEventAt) >= 5) {
            $emitEvent = true;
        }

        $this->repository->updateJobProgress($this->activeJob['id'], $progress, $emitEvent);
        if ($percent !== null) {
            $this->lastProgressPercent = $percent;
        }
        if ($emitEvent) {
            $this->lastProgressEventAt = $now;
        }
    }

    private function extractProcessedSeconds(array $packet): ?float
    {
        if (!empty($packet['out_time'])) {
            $parts = explode(':', (string) $packet['out_time']);
            if (count($parts) === 3) {
                return ((float) $parts[0] * 3600) + ((float) $parts[1] * 60) + (float) $parts[2];
            }
        }

        if (isset($packet['out_time_us']) && is_numeric($packet['out_time_us'])) {
            return ((float) $packet['out_time_us']) / 1000000.0;
        }

        if (isset($packet['out_time_ms']) && is_numeric($packet['out_time_ms'])) {
            $value = (float) $packet['out_time_ms'];
            return $value > 100000 ? $value / 1000000.0 : $value / 1000.0;
        }

        return null;
    }

    private function extractSpeed(array $packet): ?float
    {
        if (!isset($packet['speed'])) {
            return null;
        }

        $speed = rtrim((string) $packet['speed'], 'x');
        return is_numeric($speed) ? (float) $speed : null;
    }

    private function collectStderrTail(string $chunk): void
    {
        $lines = preg_split('/\r?\n/', $chunk);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $this->stderrTail[] = $line;
            if (count($this->stderrTail) > 40) {
                array_shift($this->stderrTail);
            }
        }
    }

    private function terminateProcessGroup(int $signal): void
    {
        if ($this->activePid === null || $this->activePid <= 0) {
            return;
        }

        @posix_kill(-$this->activePid, $signal);
    }

    private function cleanupActiveProcess(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->process = null;
        $this->activeJob = null;
        $this->activeCommand = null;
        $this->activePid = null;
        $this->activePaused = false;
        $this->cancelSentAt = null;
        $this->stderrBuffer = '';
        $this->stdoutBuffer = '';
        $this->progressPacket = [];
        $this->stderrTail = [];
        $this->lastProgressPercent = -1.0;
        $this->lastProgressEventAt = 0;
    }

    private function updateCompletedOutputMetadata(array $job, array $result): array
    {
        $outputPath = (string) ($result['output_path'] ?? '');
        if (!$this->shouldRefreshMatroskaTrackStatistics($outputPath)) {
            return $result;
        }

        $binary = trim($this->config->mkvpropeditPath());
        if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
            $message = 'Skipping Matroska track statistics update: mkvpropedit not available';
            $this->repository->appendEvent($job['id'], 'metadata', [
                'phase' => 'matroska_track_statistics',
                'status' => 'skipped',
                'message' => $message,
                'output_path' => $outputPath,
            ]);
            $result['metadata_update'] = [
                'status' => 'skipped',
                'message' => $message,
            ];
            return $result;
        }

        try {
            $command = [$binary, $outputPath, '--add-track-statistics-tags'];
            $processResult = $this->runBlockingCommand($command);
            if ($processResult['exit_code'] !== 0) {
                throw new \RuntimeException(trim($processResult['stderr']) !== ''
                    ? trim($processResult['stderr'])
                    : ('mkvpropedit exited with code ' . $processResult['exit_code']));
            }

            $this->repository->appendEvent($job['id'], 'metadata', [
                'phase' => 'matroska_track_statistics',
                'status' => 'completed',
                'message' => 'Matroska track statistics tags updated',
                'output_path' => $outputPath,
            ]);
            $result['metadata_update'] = [
                'status' => 'completed',
                'tool' => 'mkvpropedit',
                'command' => $command,
            ];
        } catch (\Throwable $throwable) {
            $this->repository->appendEvent($job['id'], 'metadata', [
                'phase' => 'matroska_track_statistics',
                'status' => 'failed',
                'message' => $throwable->getMessage(),
                'output_path' => $outputPath,
            ]);
            $result['metadata_update'] = [
                'status' => 'failed',
                'message' => $throwable->getMessage(),
            ];
        }

        return $result;
    }

    private function shouldRefreshMatroskaTrackStatistics(string $outputPath): bool
    {
        if ($outputPath === '' || !is_file($outputPath)) {
            return false;
        }

        $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        return in_array($extension, ['mkv', 'mka', 'webm'], true);
    }

    private function runBlockingCommand(array $command): array
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            APP_ROOT,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start helper process');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }

            usleep(100000);
        } while (true);

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $procClose = proc_close($process);
        if ($procClose >= 0) {
            $exitCode = $procClose;
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function hasBackgroundAnalysisTasks(): bool
    {
        return $this->backgroundAnalysisTasks !== [];
    }

    private function tickBackgroundAnalysisTasks(): void
    {
        if ($this->backgroundAnalysisTasks === []) {
            return;
        }

        foreach (array_keys($this->backgroundAnalysisTasks) as $taskId) {
            if (!isset($this->backgroundAnalysisTasks[$taskId])) {
                continue;
            }

            $task = &$this->backgroundAnalysisTasks[$taskId];
            $job = $this->repository->getJob($task['job_id']);
            if ($job === null || in_array($job['status'] ?? '', ['cancel_requested', 'cancelled', 'failed', 'completed'], true)) {
                unset($task);
                $this->terminateBackgroundAnalysisTask($taskId, SIGTERM);
                continue;
            }

            $this->drainBackgroundAnalysisTaskPipes($taskId);

            $status = proc_get_status($task['process']);
            if (!($status['running'] ?? false)) {
                unset($task);
                $this->finalizeBackgroundAnalysisTask($taskId, (int) ($status['exitcode'] ?? -1), $job);
                continue;
            }

            unset($task);
        }
    }

    private function dispatchBackgroundAnalysisTasks(): void
    {
        $maxTasks = $this->config->workerParallelLoudnormAnalysisMax();
        if ($maxTasks <= 0 || !$this->canDispatchBackgroundAnalysisTasks()) {
            return;
        }

        while (count($this->backgroundAnalysisTasks) < $maxTasks) {
            $candidate = $this->nextBackgroundAnalysisCandidate();
            if ($candidate === null) {
                return;
            }

            try {
                $this->startBackgroundAnalysisTask($candidate);
            } catch (\Throwable $throwable) {
                $this->repository->markJobFailed($candidate['job']['id'], $throwable->getMessage(), [
                    'failed_phase' => 'analyzing_audio',
                    'output_id' => $candidate['output_id'] ?? null,
                    'source_path' => $candidate['output']['source_path'] ?? null,
                    'message' => $throwable->getMessage(),
                ]);
                $this->cancelBackgroundAnalysisTasksForJob($candidate['job']['id']);
            }
        }
    }

    private function canDispatchBackgroundAnalysisTasks(): bool
    {
        if ($this->process === null || $this->activeJob === null || $this->activePaused) {
            return false;
        }

        if (($this->activeJob['status'] ?? '') !== 'running') {
            return false;
        }

        if (($this->activeCommand['kind'] ?? 'ffmpeg') !== 'ffmpeg') {
            return false;
        }

        return (($this->activeJob['type'] ?? 'video') === 'video');
    }

    private function nextBackgroundAnalysisCandidate(): ?array
    {
        foreach ($this->repository->listPendingJobs(40) as $job) {
            if (($job['type'] ?? 'video') !== 'video') {
                continue;
            }

            foreach ($this->collectPendingLoudnormCandidates($job) as $candidate) {
                if ($this->hasBackgroundAnalysisTask($job['id'], $candidate['cache_key'])) {
                    continue;
                }

                $prepared = $this->loudnormAnalyzer->prepareOutput($candidate['output']);
                if ($prepared === null) {
                    continue;
                }

                $candidate['job'] = $job;
                $candidate['prepared'] = $prepared;

                return $candidate;
            }
        }

        return null;
    }

    private function collectPendingLoudnormCandidates(array $job): array
    {
        $plan = $job['compiled_plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['audio_outputs'] ?? null)) {
            return [];
        }

        $candidates = [];
        $seen = [];
        foreach ($plan['audio_outputs'] as $index => $output) {
            if (!$this->isLoudnormAnalysisRequired($output)) {
                continue;
            }

            if ($this->hasLoudnormAnalysis($output['loudnorm_analysis'] ?? null)) {
                continue;
            }

            $cacheKey = $this->buildLoudnormCacheKey($output);
            if (isset($seen[$cacheKey])) {
                continue;
            }

            $seen[$cacheKey] = true;
            $candidates[] = [
                'index' => $index,
                'output' => $output,
                'output_id' => $output['output_id'] ?? ('audio_' . $index),
                'label' => $output['label'] ?? ('Audio #' . $index),
                'cache_key' => $cacheKey,
            ];
        }

        return $candidates;
    }

    private function hasBackgroundAnalysisTask(string $jobId, string $cacheKey): bool
    {
        foreach ($this->backgroundAnalysisTasks as $task) {
            if (($task['job_id'] ?? null) === $jobId && ($task['cache_key'] ?? null) === $cacheKey) {
                return true;
            }
        }

        return false;
    }

    private function startBackgroundAnalysisTask(array $candidate): void
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            array_merge(['/usr/bin/setsid'], $candidate['prepared']['command']),
            $descriptorSpec,
            $pipes,
            APP_ROOT,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start loudnorm analysis process');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $status = proc_get_status($process);
        $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
        if ($pid <= 0) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            throw new \RuntimeException('Unable to determine loudnorm analysis pid');
        }

        $taskId = bin2hex(random_bytes(8));
        $this->backgroundAnalysisTasks[$taskId] = [
            'task_id' => $taskId,
            'job_id' => $candidate['job']['id'],
            'output_id' => $candidate['output_id'],
            'label' => $candidate['label'],
            'cache_key' => $candidate['cache_key'],
            'prepared' => $candidate['prepared'],
            'process' => $process,
            'pipes' => $pipes,
            'pid' => $pid,
            'stdout' => '',
            'stderr' => '',
            'started_at' => time(),
        ];

        $this->repository->updateJobProgress(
            $candidate['job']['id'],
            $this->buildLoudnormProgressPayload(
                $candidate['job'],
                'Loudnorm-Analyse für ' . $candidate['label']
            ),
            true
        );
    }

    private function drainBackgroundAnalysisTaskPipes(string $taskId): void
    {
        if (!isset($this->backgroundAnalysisTasks[$taskId])) {
            return;
        }

        $task = &$this->backgroundAnalysisTasks[$taskId];
        foreach ([1 => 'stdout', 2 => 'stderr'] as $pipeIndex => $bufferKey) {
            if (!isset($task['pipes'][$pipeIndex]) || !is_resource($task['pipes'][$pipeIndex])) {
                continue;
            }

            $chunk = stream_get_contents($task['pipes'][$pipeIndex]);
            if (is_string($chunk) && $chunk !== '') {
                $task[$bufferKey] .= $chunk;
            }
        }

        unset($task);
    }

    private function finalizeBackgroundAnalysisTask(string $taskId, int $exitCode, ?array $job = null): void
    {
        if (!isset($this->backgroundAnalysisTasks[$taskId])) {
            return;
        }

        $task = $this->backgroundAnalysisTasks[$taskId];
        $this->drainBackgroundAnalysisTaskPipes($taskId);
        $task = $this->backgroundAnalysisTasks[$taskId];
        $finalExitCode = $this->closeBackgroundAnalysisTask($taskId);
        if ($finalExitCode >= 0) {
            $exitCode = $finalExitCode;
        }

        $job = $job ?? $this->repository->getJob($task['job_id']);
        if ($job === null || in_array($job['status'] ?? '', ['cancel_requested', 'cancelled', 'failed', 'completed'], true)) {
            return;
        }

        try {
            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    'Loudnorm-Analyse fehlgeschlagen (' . $task['label'] . ', exit ' . $exitCode . '): ' . trim((string) $task['stderr'])
                );
            }

            $measurement = $this->loudnormAnalyzer->measurementFromPrepared($task['prepared'], (string) $task['stderr']);
            $this->applyLoudnormMeasurementToJob($job, (string) $task['cache_key'], $measurement);
        } catch (\Throwable $throwable) {
            $this->cancelBackgroundAnalysisTasksForJob($task['job_id']);
            $this->repository->markJobFailed($task['job_id'], $throwable->getMessage(), [
                'failed_phase' => 'analyzing_audio',
                'output_id' => $task['output_id'] ?? null,
                'source_path' => $task['prepared']['source_path'] ?? null,
                'analysis_command' => $task['prepared']['command'] ?? [],
                'stderr' => trim((string) $task['stderr']),
            ]);
        }
    }

    private function cancelBackgroundAnalysisTasksForJob(string $jobId): void
    {
        foreach (array_keys($this->backgroundAnalysisTasks) as $taskId) {
            if (($this->backgroundAnalysisTasks[$taskId]['job_id'] ?? null) !== $jobId) {
                continue;
            }

            $this->terminateBackgroundAnalysisTask($taskId, SIGTERM);
        }
    }

    private function terminateBackgroundAnalysisTask(string $taskId, int $signal = SIGTERM): void
    {
        if (!isset($this->backgroundAnalysisTasks[$taskId])) {
            return;
        }

        $task = $this->backgroundAnalysisTasks[$taskId];
        if (!empty($task['pid'])) {
            @posix_kill(-(int) $task['pid'], $signal);
        }
        usleep(50000);
        $this->closeBackgroundAnalysisTask($taskId);
    }

    private function terminateBackgroundAnalysisTasks(): void
    {
        foreach (array_keys($this->backgroundAnalysisTasks) as $taskId) {
            $this->terminateBackgroundAnalysisTask($taskId, SIGTERM);
        }
    }

    private function closeBackgroundAnalysisTask(string $taskId): int
    {
        if (!isset($this->backgroundAnalysisTasks[$taskId])) {
            return -1;
        }

        $task = $this->backgroundAnalysisTasks[$taskId];
        foreach (($task['pipes'] ?? []) as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = -1;
        if (is_resource($task['process'])) {
            $closeCode = proc_close($task['process']);
            if ($closeCode >= 0) {
                $exitCode = $closeCode;
            }
        }

        unset($this->backgroundAnalysisTasks[$taskId]);

        return $exitCode;
    }

    private function reportState(string $state, array $payload = []): void
    {
        $signaturePayload = $payload;
        unset($signaturePayload['updated_at']);
        $signature = $state . '|' . json_encode($signaturePayload, JSON_UNESCAPED_SLASHES);
        $now = time();
        if ($signature === $this->lastReportSignature && ($now - $this->lastReportAt) < 30) {
            return;
        }

        $record = array_merge($payload, [
            'source' => 'runtime-worker',
            'task_id' => $payload['task_id'] ?? ($payload['job_id'] ?? null),
            'state' => $state,
            'pid' => getmypid(),
            'updated_at' => gmdate('c'),
        ]);

        $this->repository->setSetting('runtime_worker_state', $record);
        $this->repository->appendRuntimeEvent('worker.updated', 'worker', 'runtime', $record);

        $feedbackFile = $this->config->workerFeedbackFile();
        $directory = dirname($feedbackFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents(
            $feedbackFile,
            json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        $this->lastReportSignature = $signature;
        $this->lastReportAt = $now;
    }

    private function buildCommand(array $job): array
    {
        return match ($job['type'] ?? 'video') {
            'rar' => $this->archiveCommandBuilder->build($job),
            default => $this->ffmpegCommandBuilder->build($job),
        };
    }

    private function prepareJob(array $job): array
    {
        return match ($job['type'] ?? 'video') {
            'rar' => $job,
            default => $this->prepareVideoJob($job),
        };
    }

    private function prepareVideoJob(array $job): array
    {
        $candidates = $this->collectPendingLoudnormCandidates($job);
        if ($candidates === []) {
            return $job;
        }

        $analysisCache = [];
        foreach ($candidates as $candidate) {
            $this->repository->updateJobProgress(
                $job['id'],
                $this->buildLoudnormProgressPayload(
                    $job,
                    'Loudnorm-Analyse für ' . $candidate['label']
                ),
                true
            );

            if (!isset($analysisCache[$candidate['cache_key']])) {
                $analysisCache[$candidate['cache_key']] = $this->loudnormAnalyzer->analyzeOutput($candidate['output']);
            }

            $job = $this->applyLoudnormMeasurementToJob($job, $candidate['cache_key'], $analysisCache[$candidate['cache_key']]);
        }

        return $job;
    }

    private function applyLoudnormMeasurementToJob(array $job, string $cacheKey, array $measurement): array
    {
        $plan = $job['compiled_plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['audio_outputs'] ?? null)) {
            return $job;
        }

        $changed = false;
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $analysisResult = is_array($result['loudnorm_analysis'] ?? null) ? $result['loudnorm_analysis'] : [];

        foreach ($plan['audio_outputs'] as $index => $output) {
            if (!$this->isLoudnormAnalysisRequired($output)) {
                continue;
            }

            if ($this->buildLoudnormCacheKey($output) !== $cacheKey) {
                continue;
            }

            $outputId = $output['output_id'] ?? ('audio_' . $index);
            $alreadyMeasured = $this->hasLoudnormAnalysis($plan['audio_outputs'][$index]['loudnorm_analysis'] ?? null);
            $plan['audio_outputs'][$index]['loudnorm_analysis'] = $measurement;
            $analysisResult[$outputId] = $measurement;
            $changed = true;

            if (!$alreadyMeasured) {
                $this->repository->appendEvent($job['id'], 'analysis', [
                    'phase' => 'loudnorm',
                    'output_id' => $outputId,
                    'source_path' => $output['source_path'] ?? null,
                    'source_stream_index' => $output['source_stream_index'] ?? null,
                    'profile' => $output['loudnorm'] ?? null,
                    'measurement' => $measurement,
                ]);
            }
        }

        if (!$changed) {
            return $job;
        }

        $result['loudnorm_analysis'] = $analysisResult;
        $job['compiled_plan'] = $plan;
        $job['result'] = $result;
        $fields = [
            'compiled_plan' => $plan,
            'result' => $result,
            'progress' => $this->buildLoudnormProgressPayload($job),
            'last_heartbeat_at' => gmdate('c'),
        ];

        if (($job['status'] ?? '') === 'queued' && $this->isLoudnormPreparationComplete($job)) {
            return $this->repository->updateJobStatus($job['id'], 'ready', 'Loudnorm-Analyse abgeschlossen', $fields);
        }

        return $this->repository->updateJob($job['id'], $fields);
    }

    private function isLoudnormPreparationComplete(array $job): bool
    {
        $plan = $job['compiled_plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['audio_outputs'] ?? null)) {
            return true;
        }

        foreach ($plan['audio_outputs'] as $output) {
            if (!$this->isLoudnormAnalysisRequired($output)) {
                continue;
            }

            if (!$this->hasLoudnormAnalysis($output['loudnorm_analysis'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function buildLoudnormProgressPayload(array $job, ?string $message = null): array
    {
        [$completed, $required] = $this->loudnormProgressCounts($job);
        $isComplete = ($required === 0 || $completed >= $required);

        return [
            'phase' => $isComplete ? 'starting' : 'analyzing_audio',
            'percent' => $isComplete || $required === 0 ? 0 : round(($completed / $required) * 100, 2),
            'speed' => null,
            'eta_seconds' => null,
            'message' => $message ?? (
                $isComplete
                    ? 'Loudnorm-Analyse abgeschlossen'
                    : sprintf('Loudnorm-Analyse %d/%d', $completed, $required)
            ),
        ];
    }

    private function loudnormProgressCounts(array $job): array
    {
        $plan = $job['compiled_plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['audio_outputs'] ?? null)) {
            return [0, 0];
        }

        $completed = 0;
        $required = 0;
        foreach ($plan['audio_outputs'] as $output) {
            if (!$this->isLoudnormAnalysisRequired($output)) {
                continue;
            }

            $required++;
            if ($this->hasLoudnormAnalysis($output['loudnorm_analysis'] ?? null)) {
                $completed++;
            }
        }

        return [$completed, $required];
    }

    private function buildLoudnormCacheKey(array $output): string
    {
        return implode('|', [
            (string) ($output['source_path'] ?? ''),
            (string) ($output['source_stream_index'] ?? ''),
            (string) ($output['filter'] ?? ''),
            (string) ($output['samplerate'] ?? ''),
            (string) ($output['channels'] ?? ''),
            (string) ($output['loudnorm'] ?? ''),
        ]);
    }

    private function isLoudnormAnalysisRequired(array $output): bool
    {
        if (!$this->isOutputEnabled($output)) {
            return false;
        }

        if (($output['action'] ?? null) === 'skip') {
            return false;
        }

        $codec = trim((string) ($output['codec'] ?? 'copy'));
        if ($codec === '' || $codec === 'copy') {
            return false;
        }

        $loudnorm = trim((string) ($output['loudnorm'] ?? '0'));
        return $loudnorm !== '' && $loudnorm !== '0';
    }

    private function hasLoudnormAnalysis(mixed $analysis): bool
    {
        if (!is_array($analysis)) {
            return false;
        }

        foreach (['measured_I', 'measured_TP', 'measured_LRA', 'measured_thresh', 'offset'] as $key) {
            if (!isset($analysis[$key]) || trim((string) $analysis[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    private function isOutputEnabled(array $output): bool
    {
        if (array_key_exists('enabled', $output)) {
            return (bool) $output['enabled'];
        }

        return (($output['action'] ?? null) !== 'skip');
    }
}
