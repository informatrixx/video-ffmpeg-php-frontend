<?php

declare(strict_types=1);

namespace App;

final class FfmpegCommandBuilder
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function build(array $job): array
    {
        $plan = $job['compiled_plan'] ?? null;
        if (!is_array($plan)) {
            throw new \RuntimeException('Job has no compiled plan');
        }

        $inputs = $plan['inputs'] ?? null;
        if (!is_array($inputs) || $inputs === []) {
            $legacySourcePath = (string) ($job['source_path'] ?? ($plan['source']['path'] ?? ''));
            $inputs = [[
                'input_id' => 'primary',
                'role' => 'primary',
                'source_path' => $legacySourcePath,
            ]];
        }

        $preparedInputs = [];
        $inputIndexes = [];
        foreach ($inputs as $index => $input) {
            if (!is_array($input)) {
                continue;
            }

            $inputId = (string) ($input['input_id'] ?? '');
            if ($inputId === '') {
                throw new \RuntimeException('Each ffmpeg input requires an input_id');
            }

            $sourcePath = $this->paths->assertInputFile((string) ($input['source_path'] ?? ''));
            $preparedInputs[] = [
                'input_id' => $inputId,
                'role' => (string) ($input['role'] ?? 'joined'),
                'source_path' => $sourcePath,
            ];
            $inputIndexes[$inputId] = $index;
        }

        if ($preparedInputs === []) {
            throw new \RuntimeException('No ffmpeg inputs available');
        }

        $primaryInput = $preparedInputs[0];
        $outputFolder = $this->paths->assertWritableOutputFolder((string) ($job['output_folder'] ?? ($plan['job']['output_folder'] ?? '')));
        $resolvedOutput = $this->paths->resolveOutputPath(
            $outputFolder,
            (string) ($job['output_file'] ?? ($plan['job']['output_file'] ?? 'output.mkv'))
        );

        $command = [
            $this->config->ffmpegPath(),
            '-hide_banner',
            '-nostdin',
            '-y',
            '-progress',
            'pipe:2',
            '-stats_period',
            '1',
        ];

        foreach ($preparedInputs as $input) {
            $command[] = '-i';
            $command[] = $input['source_path'];
        }

        $videoIndex = 0;
        foreach (($plan['video_outputs'] ?? []) as $output) {
            if (!$this->isOutputEnabled($output) || ($output['action'] ?? null) === 'skip') {
                continue;
            }

            $map = $this->resolveInputMap($output, $inputIndexes);
            if ($map === null) {
                continue;
            }

            $sourceIndex = (int) ($output['source_stream_index'] ?? -1);
            if ($sourceIndex < 0) {
                continue;
            }

            $command[] = '-map';
            $command[] = $map . ':' . $sourceIndex;

            $codec = (string) ($output['codec'] ?? 'copy');
            $command[] = '-c:v:' . $videoIndex;
            $command[] = $codec;

            if ($codec !== 'copy') {
                $preset = trim((string) ($output['preset'] ?? ''));
                if ($preset !== '') {
                    $command[] = '-preset:v:' . $videoIndex;
                    $command[] = $preset;
                }

                $mode = strtolower((string) ($output['mode'] ?? 'crf'));
                $modeValue = (string) ($output['mode_value'] ?? '');
                if ($mode === 'crf' && $modeValue !== '') {
                    $command[] = '-crf:v:' . $videoIndex;
                    $command[] = $modeValue;
                } elseif (in_array($mode, ['bitrate', 'cbr'], true) && $modeValue !== '') {
                    $command[] = '-b:v:' . $videoIndex;
                    $command[] = $modeValue;
                }

                $filterChain = $this->buildVideoFilterChain($output);
                if ($filterChain !== null) {
                    $command[] = '-filter:v:' . $videoIndex;
                    $command[] = $filterChain;
                }
            }

            $videoIndex++;
        }

        $audioIndex = 0;
        foreach (($plan['audio_outputs'] ?? []) as $output) {
            if (!$this->isOutputEnabled($output) || ($output['action'] ?? null) === 'skip') {
                continue;
            }

            $map = $this->resolveInputMap($output, $inputIndexes);
            if ($map === null) {
                continue;
            }

            $sourceIndex = (int) ($output['source_stream_index'] ?? -1);
            if ($sourceIndex < 0) {
                continue;
            }

            $command[] = '-map';
            $command[] = $map . ':' . $sourceIndex;

            $codec = (string) ($output['codec'] ?? 'copy');
            $command[] = '-c:a:' . $audioIndex;
            $command[] = $codec;

            if ($codec !== 'copy') {
                $profile = trim((string) ($output['profile'] ?? ''));
                if ($profile !== '') {
                    $command[] = '-profile:a:' . $audioIndex;
                    $command[] = $profile;
                }

                $bitrate = trim((string) ($output['bitrate'] ?? ''));
                if ($bitrate !== '') {
                    $command[] = '-b:a:' . $audioIndex;
                    $command[] = $bitrate;
                }

                $sampleRate = (int) ($output['samplerate'] ?? 0);
                if ($sampleRate > 0) {
                    $command[] = '-ar:a:' . $audioIndex;
                    $command[] = (string) $sampleRate;
                }

                $channels = $this->normalizeAudioChannels($output['channels'] ?? null);
                if ($channels !== null && $channels > 0) {
                    $command[] = '-ac:a:' . $audioIndex;
                    $command[] = (string) $channels;
                }

                $filterChain = $this->buildAudioFilterChain($output);
                if ($filterChain !== null) {
                    $command[] = '-filter:a:' . $audioIndex;
                    $command[] = $filterChain;
                }
            }

            $title = trim((string) ($output['title'] ?? ''));
            if ($title !== '') {
                $command[] = '-metadata:s:a:' . $audioIndex;
                $command[] = 'title=' . $title;
            }

            $command = $this->appendDispositionFlags(
                $command,
                'a',
                $audioIndex,
                (bool) ($output['disposition_default'] ?? false),
                (bool) ($output['disposition_forced'] ?? false)
            );

            $audioIndex++;
        }

        $subtitleIndex = 0;
        foreach (($plan['subtitle_outputs'] ?? []) as $output) {
            if (!$this->isOutputEnabled($output) || ($output['action'] ?? null) === 'skip') {
                continue;
            }

            $map = $this->resolveInputMap($output, $inputIndexes);
            if ($map === null) {
                continue;
            }

            $sourceIndex = (int) ($output['source_stream_index'] ?? -1);
            if ($sourceIndex < 0) {
                continue;
            }

            $command[] = '-map';
            $command[] = $map . ':' . $sourceIndex;
            $command[] = '-c:s:' . $subtitleIndex;
            $command[] = (string) ($output['codec'] ?? 'copy');

            $title = trim((string) ($output['title'] ?? ''));
            if ($title !== '') {
                $command[] = '-metadata:s:s:' . $subtitleIndex;
                $command[] = 'title=' . $title;
            }

            $command = $this->appendDispositionFlags(
                $command,
                's',
                $subtitleIndex,
                (bool) ($output['disposition_default'] ?? false),
                (bool) ($output['disposition_forced'] ?? false)
            );

            $subtitleIndex++;
        }

        $jobTitle = trim((string) ($plan['job']['title'] ?? ''));
        if ($jobTitle !== '') {
            $command[] = '-metadata';
            $command[] = 'title=' . $jobTitle;
        }

        $command = $this->appendOutputContainerOptions($command, $resolvedOutput, $job);
        $command[] = $resolvedOutput['path'];

        return [
            'command' => $command,
            'kind' => 'ffmpeg',
            'source_path' => $primaryInput['source_path'],
            'input_paths' => array_map(
                static fn (array $input): string => $input['source_path'],
                $preparedInputs
            ),
            'output' => $resolvedOutput,
            'output_path' => $resolvedOutput['path'],
        ];
    }

    private function resolveInputMap(array $output, array $inputIndexes): ?string
    {
        $inputId = (string) ($output['input_id'] ?? 'primary');
        if (!array_key_exists($inputId, $inputIndexes)) {
            return null;
        }

        return (string) $inputIndexes[$inputId];
    }

    private function isOutputEnabled(array $output): bool
    {
        if (array_key_exists('enabled', $output)) {
            return (bool) $output['enabled'];
        }

        return (($output['action'] ?? null) !== 'skip');
    }

    private function appendOutputContainerOptions(array $command, array $resolvedOutput, array $job): array
    {
        $extension = strtolower((string) pathinfo((string) ($resolvedOutput['file_name'] ?? $resolvedOutput['path'] ?? ''), PATHINFO_EXTENSION));

        if (in_array($extension, ['mp4', 'm4v', 'mov', '3gp'], true)) {
            $command[] = '-movflags';
            $command[] = '+faststart';
            return $command;
        }

        if (in_array($extension, ['mkv', 'mka', 'webm'], true)) {
            $command[] = '-reserve_index_space';
            $command[] = (string) $this->matroskaReserveIndexSpace($job);
            $command[] = '-cues_to_front';
            $command[] = '1';
        }

        return $command;
    }

    private function matroskaReserveIndexSpace(array $job): int
    {
        $durationSeconds = (float) ($job['analysis']['info']['duration_seconds'] ?? 0.0);
        if ($durationSeconds <= 0.0) {
            return 50000;
        }

        $hours = $durationSeconds / 3600;
        return max(50000, (int) ceil($hours * 50000));
    }

    private function appendDispositionFlags(array $command, string $streamType, int $streamIndex, bool $isDefault, bool $isForced): array
    {
        $flags = [];
        if ($isDefault) {
            $flags[] = 'default';
        }
        if ($isForced) {
            $flags[] = 'forced';
        }

        $command[] = '-disposition:' . $streamType . ':' . $streamIndex;
        $command[] = $flags !== [] ? implode('+', $flags) : '0';

        return $command;
    }

    private function buildVideoFilterChain(array $output): ?string
    {
        $filters = [];

        $crop = trim((string) ($output['crop'] ?? ''));
        if ($crop !== '' && strtolower($crop) !== 'auto' && strtolower($crop) !== 'false' && $crop !== '0') {
            $filters[] = 'crop=' . $crop;
        }

        $resizeFilter = $this->normalizeResizeFilter($output['resize'] ?? null);
        if ($resizeFilter !== null) {
            $filters[] = $resizeFilter;
        }

        $nlmeans = $this->normalizeNlmeansFilter($output['nlmeans'] ?? null);
        if ($nlmeans !== null) {
            $filters[] = $nlmeans;
        }

        return $filters !== [] ? implode(',', $filters) : null;
    }

    private function buildAudioFilterChain(array $output): ?string
    {
        $filters = [];

        $customFilter = trim((string) ($output['filter'] ?? ''));
        if ($customFilter !== '' && $customFilter !== '0') {
            $filters[] = $customFilter;
        }

        $loudnormProfile = $this->resolveLoudnormProfile((string) ($output['loudnorm'] ?? '0'));
        if ($loudnormProfile !== null) {
            $filters[] = $this->buildLoudnormFilter($loudnormProfile, $output['loudnorm_analysis'] ?? null);
        }

        return $filters !== [] ? implode(',', $filters) : null;
    }

    private function buildLoudnormFilter(array $profile, mixed $analysis): string
    {
        $base = sprintf(
            'loudnorm=I=%s:TP=%s:LRA=%s',
            $this->filterValue($profile['I']),
            $this->filterValue($profile['TP']),
            $this->filterValue($profile['LRA'])
        );

        if (!is_array($analysis) || !$this->hasCompleteLoudnormAnalysis($analysis)) {
            return $base;
        }

        return $base . sprintf(
            ':measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true:print_format=summary',
            $this->filterValue($analysis['measured_I']),
            $this->filterValue($analysis['measured_TP']),
            $this->filterValue($analysis['measured_LRA']),
            $this->filterValue($analysis['measured_thresh']),
            $this->filterValue($analysis['offset'])
        );
    }

    private function hasCompleteLoudnormAnalysis(array $analysis): bool
    {
        foreach (['measured_I', 'measured_TP', 'measured_LRA', 'measured_thresh', 'offset'] as $key) {
            if (!isset($analysis[$key]) || trim((string) $analysis[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    private function resolveLoudnormProfile(string $profileKey): ?array
    {
        $profileKey = trim($profileKey);
        if ($profileKey === '' || $profileKey === '0') {
            return null;
        }

        $profiles = $this->config->static()['audio']['loudnorm'] ?? [];
        $profile = $profiles[$profileKey] ?? null;
        if (!is_array($profile) || !isset($profile['I'], $profile['TP'], $profile['LRA'])) {
            throw new \RuntimeException("Unsupported loudnorm profile: {$profileKey}");
        }

        return $profile;
    }

    private function filterValue(mixed $value): string
    {
        return str_replace(',', '.', trim((string) $value));
    }

    private function normalizeResizeFilter(mixed $resize): ?string
    {
        if ($resize === false || $resize === null) {
            return null;
        }

        $resize = strtolower(trim((string) $resize));
        if ($resize === '' || $resize === 'false' || $resize === '0' || $resize === 'auto') {
            return null;
        }

        $namedResolutions = [
            'sd' => 480,
            '720' => 720,
            '1080' => 1080,
            '4k' => 2160,
            '2160' => 2160,
        ];

        if (isset($namedResolutions[$resize])) {
            return 'scale=-2:' . $namedResolutions[$resize];
        }

        if (preg_match('/^\d+$/', $resize) === 1) {
            return 'scale=-2:' . $resize;
        }

        if (preg_match('/^(\d+)x(\d+)$/', $resize, $matches) === 1) {
            return 'scale=' . $matches[1] . ':' . $matches[2];
        }

        return null;
    }

    private function normalizeNlmeansFilter(mixed $nlmeans): ?string
    {
        $nlmeans = strtolower(trim((string) ($nlmeans ?? '')));
        return match ($nlmeans) {
            '', '0', 'false' => null,
            'light' => 'nlmeans=s=1.5:p=7:r=9',
            'medium' => 'nlmeans=s=2.5:p=7:r=15',
            'strong' => 'nlmeans=s=4:p=7:r=15',
            default => str_starts_with($nlmeans, 'nlmeans=') ? $nlmeans : null,
        };
    }

    private function normalizeAudioChannels(mixed $channels): ?int
    {
        if ($channels === null || $channels === false || $channels === '') {
            return null;
        }

        if (is_int($channels)) {
            return $channels;
        }

        $channels = strtolower(trim((string) $channels));
        if ($channels === 'dpl') {
            return 2;
        }

        if (preg_match('/^\d+$/', $channels) === 1) {
            return (int) $channels;
        }

        return null;
    }
}
