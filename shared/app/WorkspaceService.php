<?php

declare(strict_types=1);

namespace App;

final class WorkspaceService
{
    private Config $config;
    private PathService $paths;
    private MediaAnalyzer $analyzer;
    private ArchiveAnalyzer $archiveAnalyzer;
    private TemplateEngine $templateEngine;
    private Repository $repository;

    public function __construct(
        Config $config,
        PathService $paths,
        MediaAnalyzer $analyzer,
        ArchiveAnalyzer $archiveAnalyzer,
        TemplateEngine $templateEngine,
        Repository $repository
    ) {
        $this->config = $config;
        $this->paths = $paths;
        $this->analyzer = $analyzer;
        $this->archiveAnalyzer = $archiveAnalyzer;
        $this->templateEngine = $templateEngine;
        $this->repository = $repository;
    }

    public function editorOptions(): array
    {
        $static = $this->config->static();
        $legacy = json_decode((string) file_get_contents(APP_ROOT . 'config/decision_template.json'), true);

        return [
            'audio' => [
                'codecs' => $static['audio']['codecs'] ?? [],
                'channels' => $static['audio']['channels'] ?? [],
                'bitrate' => $static['audio']['bitrate'] ?? [],
                'samplerate' => $static['audio']['samplerate'] ?? [],
                'loudnorm' => $static['audio']['loudnorm'] ?? [],
            ],
            'video' => [
                'codecs' => $static['video']['codecs'] ?? [],
                'nlmeans' => $static['video']['nlmeans'] ?? [],
                'resize' => $legacy['video'] ?? [],
            ],
            'templates' => [
                'variants' => $legacy['presets'] ?? [],
            ],
            'output_history' => $this->getOutputHistory(),
        ];
    }

    public function buildVideoWorkspace(
        string $primarySourcePath,
        array $joinedSources,
        ?int $templateId,
        ?int $templateVersionId,
        ?string $variant,
        ?string $outputFolder = null
    ): array {
        $primaryAnalysis = $this->analyzer->analyze($primarySourcePath);
        $selection = $this->repository->getPublishedTemplate($templateId);
        if ($templateVersionId !== null) {
            foreach ($selection['template']['versions'] as $version) {
                if ((int) $version['id'] === $templateVersionId) {
                    $selection['version'] = $version;
                    break;
                }
            }
        }

        $joinedAnalyses = [];
        foreach ($this->normalizeJoinedSources($joinedSources, $primaryAnalysis['file']) as $path) {
            $joinedAnalyses[] = $this->analyzer->analyzeJoinSource($path);
        }

        $effectiveOutputFolder = $outputFolder !== null && trim($outputFolder) !== ''
            ? $this->paths->assertOutputFolder($outputFolder)
            : $this->defaultOutputFolderForSource($primaryAnalysis['file']);

        $primaryPlan = $this->templateEngine->compile(
            $primaryAnalysis,
            $selection['version']['schema'],
            $variant,
            ['output_folder' => $effectiveOutputFolder]
        );

        $plan = [
            'type' => 'video',
            'schema_version' => 1,
            'template' => [
                'id' => (int) $selection['template']['id'],
                'name' => (string) $selection['template']['name'],
                'version_id' => (int) $selection['version']['id'],
                'version' => (int) $selection['version']['version'],
            ],
            'naming' => [
                'audio' => (string) (($selection['version']['schema']['naming']['audio'] ?? '') ?: ''),
                'subtitle' => (string) (($selection['version']['schema']['naming']['subtitle'] ?? '') ?: ''),
            ],
            'variant' => $variant ?: ($primaryPlan['variant'] ?? null),
            'job' => [
                'title' => (string) ($primaryPlan['job']['title'] ?? $primaryAnalysis['base_name']),
                'output_folder' => $effectiveOutputFolder,
                'output_file' => $this->paths->normalizeOutputFileName(
                    $primaryPlan['job']['output_file'] ?? null,
                    $primaryAnalysis['base_name']
                ),
            ],
            'inputs' => [],
            'video_outputs' => [],
            'audio_outputs' => [],
            'subtitle_outputs' => [],
        ];

        $primaryInputId = 'primary';
        $plan['inputs'][] = $this->makeInputDescriptor($primaryInputId, 'primary', $primaryAnalysis);
        $primaryOutputs = $this->hydratePlanOutputs($primaryPlan, $primaryAnalysis, $primaryInputId, true);
        $plan['video_outputs'] = array_merge($plan['video_outputs'], $primaryOutputs['video']);
        $plan['audio_outputs'] = array_merge($plan['audio_outputs'], $primaryOutputs['audio']);
        $plan['subtitle_outputs'] = array_merge($plan['subtitle_outputs'], $primaryOutputs['subtitle']);

        foreach ($joinedAnalyses as $index => $analysis) {
            $inputId = 'join_' . ($index + 1);
            $plan['inputs'][] = $this->makeInputDescriptor($inputId, 'joined', $analysis);
            $joinedPlan = $this->templateEngine->compile(
                $analysis,
                $selection['version']['schema'],
                $variant,
                ['output_folder' => $effectiveOutputFolder]
            );
            $joinedOutputs = $this->hydratePlanOutputs($joinedPlan, $analysis, $inputId, false);
            $plan['video_outputs'] = array_merge($plan['video_outputs'], $joinedOutputs['video']);
            $plan['audio_outputs'] = array_merge($plan['audio_outputs'], $joinedOutputs['audio']);
            $plan['subtitle_outputs'] = array_merge($plan['subtitle_outputs'], $joinedOutputs['subtitle']);
        }

        $plan['video_outputs'] = $this->enforceSingleEnabledVideoOutput($plan['video_outputs']);

        return [
            'mode' => 'video',
            'analysis' => $primaryAnalysis,
            'joined_analyses' => $joinedAnalyses,
            'plan' => $plan,
            'editor_options' => $this->editorOptions(),
            'output_history' => $this->getOutputHistory($effectiveOutputFolder),
        ];
    }

    public function buildArchiveWorkspace(string $archivePath): array
    {
        $analysis = $this->archiveAnalyzer->analyze($archivePath);
        $outputFolder = $this->defaultOutputFolderForSource($analysis['file']);

        return [
            'mode' => 'rar',
            'analysis' => $analysis,
            'plan' => [
                'type' => 'rar',
                'schema_version' => 1,
                'job' => [
                    'title' => $analysis['base_name'],
                    'output_folder' => $outputFolder,
                    'output_file' => $analysis['file_name'],
                ],
                'source' => [
                    'path' => $analysis['file'],
                ],
                'archive_entries' => $analysis['archive_files'],
                'options' => $analysis['job_defaults'],
            ],
            'output_history' => $this->getOutputHistory($outputFolder),
        ];
    }

    public function queueVideoJob(array $payload): array
    {
        $plan = $payload['plan'] ?? null;
        if (!is_array($plan)) {
            throw new \RuntimeException('Missing video plan');
        }

        $inputs = $plan['inputs'] ?? null;
        if (!is_array($inputs) || $inputs === []) {
            throw new \RuntimeException('Video plan has no inputs');
        }

        $primarySource = null;
        $analysisInputs = [];
        $sanitizedInputs = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) {
                continue;
            }
            $role = (string) ($input['role'] ?? 'joined');
            $sourcePath = $role === 'primary'
                ? $this->analyzer->analyze((string) ($input['source_path'] ?? ''))
                : $this->analyzer->analyzeJoinSource((string) ($input['source_path'] ?? ''));

            $inputId = (string) ($input['input_id'] ?? '');
            if ($inputId === '') {
                throw new \RuntimeException('Each input requires an input_id');
            }

            $analysisInputs[$inputId] = $sourcePath;
            $sanitizedInputs[] = $this->makeInputDescriptor($inputId, $role, $sourcePath);
            if ($role === 'primary') {
                $primarySource = $sourcePath;
            }
        }

        if ($primarySource === null) {
            throw new \RuntimeException('Video plan requires a primary input');
        }

        $outputFolder = $this->paths->assertWritableOutputFolder((string) ($plan['job']['output_folder'] ?? ''));
        $outputFile = $this->paths->normalizeOutputFileName(
            (string) ($plan['job']['output_file'] ?? ''),
            $primarySource['base_name']
        );
        $title = trim((string) ($plan['job']['title'] ?? $primarySource['base_name']));
        $this->rememberOutputFolder($outputFolder);

        $finalPlan = [
            'type' => 'video',
            'schema_version' => 1,
            'template' => $plan['template'] ?? null,
            'variant' => $plan['variant'] ?? null,
            'job' => [
                'title' => $title !== '' ? $title : $primarySource['base_name'],
                'output_folder' => $outputFolder,
                'output_file' => $outputFile,
            ],
            'inputs' => $sanitizedInputs,
            'video_outputs' => $this->sanitizeVideoOutputs($plan['video_outputs'] ?? [], $analysisInputs),
            'audio_outputs' => $this->sanitizeAudioOutputs($plan['audio_outputs'] ?? [], $analysisInputs),
            'subtitle_outputs' => $this->sanitizeSubtitleOutputs($plan['subtitle_outputs'] ?? [], $analysisInputs),
        ];

        $enabledVideoOutputs = array_values(array_filter(
            $finalPlan['video_outputs'],
            static fn (array $output): bool => !empty($output['enabled'])
        ));
        if ($enabledVideoOutputs === []) {
            throw new \RuntimeException('At least one video output must remain enabled');
        }
        if (count($enabledVideoOutputs) > 1) {
            throw new \RuntimeException('Only one video output can be enabled at the same time');
        }

        $job = $this->repository->createJob([
            'type' => 'video',
            'source_path' => $primarySource['file'],
            'output_folder' => $outputFolder,
            'output_file' => $outputFile,
            'title' => $finalPlan['job']['title'],
            'status' => 'queued',
            'template_id' => isset($finalPlan['template']['id']) ? (int) $finalPlan['template']['id'] : null,
            'template_version_id' => isset($finalPlan['template']['version_id']) ? (int) $finalPlan['template']['version_id'] : null,
            'analysis' => [
                'info' => $primarySource['info'],
                'primary' => $primarySource,
                'joined_inputs' => array_values(array_filter(
                    $analysisInputs,
                    static fn (array $input, string $inputId): bool => $inputId !== 'primary',
                    ARRAY_FILTER_USE_BOTH
                )),
            ],
            'compiled_plan' => $finalPlan,
        ]);

        return $job;
    }

    public function queueArchiveJob(array $payload): array
    {
        $sourcePath = (string) ($payload['source_path'] ?? '');
        $analysis = $this->archiveAnalyzer->analyze($sourcePath);
        $outputFolder = $this->paths->assertWritableOutputFolder((string) ($payload['output_folder'] ?? ''));
        $title = trim((string) ($payload['title'] ?? $analysis['base_name']));
        $selectedEntries = $payload['selected_entries'] ?? [];
        if (!is_array($selectedEntries) || $selectedEntries === []) {
            throw new \RuntimeException('Select at least one archive entry');
        }

        $validEntries = array_column($analysis['archive_files'], null, 'entry_name');
        $finalEntries = [];
        $seenEntries = [];
        foreach ($selectedEntries as $entryName) {
            if (!is_string($entryName) || !isset($validEntries[$entryName])) {
                throw new \RuntimeException('Invalid archive entry selected');
            }

            if (isset($seenEntries[$entryName])) {
                continue;
            }

            $seenEntries[$entryName] = true;
            $finalEntries[] = $validEntries[$entryName];
        }

        $ignorePaths = $this->toBool($payload['ignore_paths'] ?? true);
        $overwrite = $this->toBool($payload['overwrite'] ?? true);
        $this->rememberOutputFolder($outputFolder);

        $plan = [
            'type' => 'rar',
            'schema_version' => 1,
            'job' => [
                'title' => $title !== '' ? $title : $analysis['base_name'],
                'output_folder' => $outputFolder,
                'output_file' => $analysis['file_name'],
            ],
            'source' => [
                'path' => $analysis['file'],
            ],
            'archive_entries' => $finalEntries,
            'options' => [
                'ignore_paths' => $ignorePaths,
                'overwrite' => $overwrite,
            ],
        ];

        return $this->repository->createJob([
            'type' => 'rar',
            'source_path' => $analysis['file'],
            'output_folder' => $outputFolder,
            'output_file' => $analysis['file_name'],
            'title' => $title !== '' ? $title : $analysis['base_name'],
            'status' => 'queued',
            'analysis' => $analysis,
            'compiled_plan' => $plan,
        ]);
    }

    public function getOutputHistory(?string $preferredFolder = null): array
    {
        $history = $this->repository->getSetting('output_history', null);
        if (!is_array($history)) {
            $history = [];
            $legacyFile = APP_ROOT . 'config/outfolder_history.json';
            if (is_file($legacyFile)) {
                $legacyHistory = json_decode((string) file_get_contents($legacyFile), true);
                if (is_array($legacyHistory)) {
                    foreach ($legacyHistory as $folder) {
                        if (is_string($folder) && $folder !== '') {
                            $history[] = $folder;
                        }
                    }
                }
            }
        }

        if ($preferredFolder !== null && $preferredFolder !== '') {
            array_unshift($history, $preferredFolder);
        }

        $history = array_values(array_unique(array_filter(
            $history,
            fn (mixed $folder): bool => is_string($folder) && $folder !== ''
        )));

        return array_slice($history, 0, 12);
    }

    public function rememberOutputFolder(string $folder): void
    {
        $folder = $this->paths->assertOutputFolder($folder);
        $history = $this->getOutputHistory($folder);
        $this->repository->setSetting('output_history', array_slice($history, 0, 12));
    }

    private function normalizeJoinedSources(array $joinedSources, string $primarySource): array
    {
        $normalized = [];
        foreach ($joinedSources as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidate = $this->paths->assertInputFile($candidate);
            if ($candidate === $primarySource) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function defaultOutputFolderForSource(string $sourcePath): string
    {
        $candidate = dirname($sourcePath);
        try {
            return $this->paths->assertWritableOutputFolder($candidate);
        } catch (\Throwable) {
            $roots = $this->config->outputRoots();

            foreach ($roots as $rootPath) {
                if (!is_string($rootPath) || $rootPath === '') {
                    continue;
                }

                try {
                    return $this->paths->assertWritableOutputFolder($rootPath);
                } catch (\Throwable) {
                    continue;
                }
            }

            $first = reset($roots);
            if (!is_string($first) || $first === '') {
                throw new \RuntimeException('No output roots configured');
            }

            return $this->paths->assertOutputFolder($first);
        }
    }

    private function makeInputDescriptor(string $inputId, string $role, array $analysis): array
    {
        return [
            'input_id' => $inputId,
            'role' => $role,
            'source_path' => $analysis['file'],
            'file_name' => $analysis['file_name'],
            'type' => $analysis['type'],
            'info' => [
                'format_name' => $analysis['info']['format_name'] ?? '',
                'duration_human' => $analysis['info']['duration_human'] ?? '00:00:00',
                'duration_seconds' => $analysis['info']['duration_seconds'] ?? 0,
                'size_human' => $analysis['info']['size_human'] ?? '0B',
                'video_stream_count' => $analysis['info']['video_stream_count'] ?? count($analysis['streams']['video'] ?? []),
                'audio_stream_count' => $analysis['info']['audio_stream_count'] ?? count($analysis['streams']['audio'] ?? []),
                'subtitle_stream_count' => $analysis['info']['subtitle_stream_count'] ?? count($analysis['streams']['subtitle'] ?? []),
            ],
        ];
    }

    private function hydratePlanOutputs(array $compiledPlan, array $analysis, string $inputId, bool $enabledByDefault): array
    {
        $result = [
            'video' => [],
            'audio' => [],
            'subtitle' => [],
        ];

        $videoCounter = 0;
        foreach (($compiledPlan['video_outputs'] ?? []) as $output) {
            $stream = $this->findStream($analysis['streams']['video'] ?? [], (int) ($output['source_stream_index'] ?? -1));
            if ($stream === null) {
                continue;
            }

            $result['video'][] = [
                'output_id' => 'video_' . $inputId . '_' . $videoCounter++,
                'scope' => 'video',
                'input_id' => $inputId,
                'source_path' => $analysis['file'],
                'source_stream_index' => $stream['stream_index'],
                'enabled' => $enabledByDefault && $videoCounter === 1,
                'action' => (string) ($output['action'] ?? 'encode'),
                'codec' => (string) ($output['codec'] ?? 'libx265'),
                'mode' => (string) ($output['mode'] ?? 'crf'),
                'mode_value' => $output['mode_value'] ?? 23,
                'preset' => (string) ($output['preset'] ?? 'slow'),
                'crop' => (string) ($output['crop'] ?? 'auto'),
                'resize' => $output['resize'] ?? '0',
                'nlmeans' => (string) ($output['nlmeans'] ?? '0'),
                'label' => sprintf(
                    '%s · %sx%s · %s',
                    $analysis['file_name'],
                    (string) ($stream['width'] ?? 0),
                    (string) ($stream['height'] ?? 0),
                    (string) ($stream['codec']['name_uc'] ?? '')
                ),
            ];
        }

        $audioCounter = 0;
        foreach (($compiledPlan['audio_outputs'] ?? []) as $output) {
            $stream = $this->findStream($analysis['streams']['audio'] ?? [], (int) ($output['source_stream_index'] ?? -1));
            if ($stream === null) {
                continue;
            }

            $result['audio'][] = [
                'output_id' => 'audio_' . $inputId . '_' . $audioCounter++,
                'scope' => 'audio',
                'input_id' => $inputId,
                'source_path' => $analysis['file'],
                'source_stream_index' => $stream['stream_index'],
                'enabled' => $enabledByDefault,
                'action' => (string) ($output['action'] ?? 'encode'),
                'codec' => (string) ($output['codec'] ?? 'libfdk_aac'),
                'profile' => $output['profile'] ?? null,
                'bitrate' => $output['bitrate'] ?? null,
                'samplerate' => $output['samplerate'] ?? null,
                'channels' => $output['channels'] ?? $stream['channels']['count'] ?? 2,
                'filter' => $output['filter'] ?? null,
                'loudnorm' => $output['loudnorm'] ?? '0',
                'title' => (string) ($output['title'] ?? ($stream['title'] ?? '')),
                'disposition_default' => (bool) ($output['disposition_default'] ?? false),
                'disposition_forced' => (bool) ($output['disposition_forced'] ?? false),
                'label' => sprintf(
                    '%s · %s · %s · %s',
                    $analysis['file_name'],
                    (string) ($stream['language']['human'] ?? 'Unknown'),
                    (string) ($stream['channels']['layout'] ?? ''),
                    (string) ($stream['codec']['name_uc'] ?? '')
                ),
            ];
        }

        $subtitleCounter = 0;
        foreach (($compiledPlan['subtitle_outputs'] ?? []) as $output) {
            $stream = $this->findStream($analysis['streams']['subtitle'] ?? [], (int) ($output['source_stream_index'] ?? -1));
            if ($stream === null) {
                continue;
            }

            $action = (string) ($output['action'] ?? 'copy');
            $result['subtitle'][] = [
                'output_id' => 'subtitle_' . $inputId . '_' . $subtitleCounter++,
                'scope' => 'subtitle',
                'input_id' => $inputId,
                'source_path' => $analysis['file'],
                'source_stream_index' => $stream['stream_index'],
                'enabled' => $enabledByDefault && $action !== 'skip',
                'action' => 'copy',
                'codec' => (string) (($output['codec'] ?? null) ?: 'copy'),
                'title' => (string) ($output['title'] ?? ($stream['title'] ?? '')),
                'disposition_default' => (bool) ($output['disposition_default'] ?? false),
                'disposition_forced' => (bool) ($output['disposition_forced'] ?? false),
                'label' => sprintf(
                    '%s · %s · %s',
                    $analysis['file_name'],
                    (string) ($stream['language']['human'] ?? 'Unknown'),
                    (string) ($stream['codec']['name_uc'] ?? '')
                ),
            ];
        }

        return $result;
    }

    private function enforceSingleEnabledVideoOutput(array $outputs): array
    {
        $foundEnabled = false;
        foreach ($outputs as &$output) {
            if (!empty($output['enabled']) && !$foundEnabled) {
                $foundEnabled = true;
                continue;
            }

            $output['enabled'] = false;
        }
        unset($output);

        if (!$foundEnabled && $outputs !== []) {
            $outputs[0]['enabled'] = true;
        }

        return $outputs;
    }

    private function sanitizeVideoOutputs(array $outputs, array $analysisInputs): array
    {
        $staticVideoCodecs = $this->config->static()['video']['codecs'] ?? [];
        $sanitized = [];

        foreach ($outputs as $index => $output) {
            if (!is_array($output)) {
                continue;
            }

            $inputId = (string) ($output['input_id'] ?? '');
            $analysis = $analysisInputs[$inputId] ?? null;
            if (!is_array($analysis)) {
                throw new \RuntimeException('Video output references an unknown input');
            }

            $streamIndex = (int) ($output['source_stream_index'] ?? -1);
            if ($this->findStream($analysis['streams']['video'] ?? [], $streamIndex) === null) {
                throw new \RuntimeException('Video output references an invalid source stream');
            }

            $codec = (string) ($output['codec'] ?? 'copy');
            if (!isset($staticVideoCodecs[$codec])) {
                throw new \RuntimeException("Unsupported video codec: {$codec}");
            }

            $mode = strtolower((string) ($output['mode'] ?? 'crf'));
            if (!in_array($mode, ['crf', 'cbr', 'bitrate'], true)) {
                $mode = 'crf';
            }

            $sanitized[] = [
                'output_id' => (string) ($output['output_id'] ?? ('video_' . $index)),
                'scope' => 'video',
                'input_id' => $inputId,
                'source_path' => $analysis['file'],
                'source_stream_index' => $streamIndex,
                'enabled' => $this->toBool($output['enabled'] ?? false),
                'action' => $codec === 'copy' ? 'copy' : 'encode',
                'codec' => $codec,
                'mode' => $mode,
                'mode_value' => is_numeric($output['mode_value'] ?? null) ? $output['mode_value'] + 0 : 23,
                'preset' => (string) ($output['preset'] ?? 'slow'),
                'crop' => (string) ($output['crop'] ?? 'auto'),
                'resize' => $output['resize'] ?? '0',
                'nlmeans' => (string) ($output['nlmeans'] ?? '0'),
                'label' => (string) ($output['label'] ?? $analysis['file_name']),
            ];
        }

        return $sanitized;
    }

    private function sanitizeAudioOutputs(array $outputs, array $analysisInputs): array
    {
        $static = $this->config->static();
        $audioCodecs = $static['audio']['codecs'] ?? [];
        $channels = $static['audio']['channels'] ?? [];
        $samplerates = array_map('strval', $static['audio']['samplerate'] ?? []);
        $loudnorm = array_keys($static['audio']['loudnorm'] ?? []);
        $sanitized = [];

        foreach ($outputs as $index => $output) {
            if (!is_array($output)) {
                continue;
            }

            $inputId = (string) ($output['input_id'] ?? '');
            $analysis = $analysisInputs[$inputId] ?? null;
            if (!is_array($analysis)) {
                throw new \RuntimeException('Audio output references an unknown input');
            }

            $streamIndex = (int) ($output['source_stream_index'] ?? -1);
            $stream = $this->findStream($analysis['streams']['audio'] ?? [], $streamIndex);
            if ($stream === null) {
                throw new \RuntimeException('Audio output references an invalid source stream');
            }

            $codec = (string) ($output['codec'] ?? 'copy');
            if (!isset($audioCodecs[$codec])) {
                throw new \RuntimeException("Unsupported audio codec: {$codec}");
            }

            $profile = $output['profile'] ?? null;
            if ($profile !== null && isset($audioCodecs[$codec]['profile']) && !array_key_exists((string) $profile, $audioCodecs[$codec]['profile'])) {
                $profile = array_key_first($audioCodecs[$codec]['profile']);
            }

            $channelValue = (string) ($output['channels'] ?? ($stream['channels']['count'] ?? 2));
            if (!array_key_exists($channelValue, $channels) && !ctype_digit($channelValue)) {
                $channelValue = (string) ($stream['channels']['count'] ?? 2);
            }

            $sampleRate = (string) ($output['samplerate'] ?? ($stream['sample_rate'] ?? '48000'));
            if (!in_array($sampleRate, $samplerates, true) && !ctype_digit($sampleRate)) {
                $sampleRate = (string) ($stream['sample_rate'] ?? '48000');
            }

            $loudnormValue = (string) ($output['loudnorm'] ?? '0');
            if (!in_array($loudnormValue, $loudnorm, true)) {
                $loudnormValue = '0';
            }

            $sanitized[] = [
                'output_id' => (string) ($output['output_id'] ?? ('audio_' . $index)),
                'scope' => 'audio',
                'input_id' => $inputId,
                'source_path' => $analysis['file'],
                'source_stream_index' => $streamIndex,
                'enabled' => $this->toBool($output['enabled'] ?? false),
                'action' => $codec === 'copy' ? 'copy' : 'encode',
                'codec' => $codec,
                'profile' => $profile,
                'bitrate' => (string) ($output['bitrate'] ?? ''),
                'samplerate' => (int) $sampleRate,
                'channels' => $channelValue,
                'filter' => isset($output['filter']) ? (string) $output['filter'] : null,
                'loudnorm' => $loudnormValue,
                'title' => trim((string) ($output['title'] ?? ($stream['title'] ?? ''))),
                'disposition_default' => $this->toBool($output['disposition_default'] ?? false),
                'disposition_forced' => $this->toBool($output['disposition_forced'] ?? false),
                'label' => (string) ($output['label'] ?? $analysis['file_name']),
            ];
        }

        return $sanitized;
    }

    private function sanitizeSubtitleOutputs(array $outputs, array $analysisInputs): array
    {
        $sanitized = [];

        foreach ($outputs as $index => $output) {
            if (!is_array($output)) {
                continue;
            }

            $inputId = (string) ($output['input_id'] ?? '');
            $analysis = $analysisInputs[$inputId] ?? null;
            if (!is_array($analysis)) {
                throw new \RuntimeException('Subtitle output references an unknown input');
            }

            $streamIndex = (int) ($output['source_stream_index'] ?? -1);
            if ($this->findStream($analysis['streams']['subtitle'] ?? [], $streamIndex) === null) {
                throw new \RuntimeException('Subtitle output references an invalid source stream');
            }

            $sanitized[] = [
                'output_id' => (string) ($output['output_id'] ?? ('subtitle_' . $index)),
                'scope' => 'subtitle',
                'input_id' => $inputId,
                'source_path' => $analysis['file'],
                'source_stream_index' => $streamIndex,
                'enabled' => $this->toBool($output['enabled'] ?? false),
                'action' => 'copy',
                'codec' => 'copy',
                'title' => trim((string) ($output['title'] ?? '')),
                'disposition_default' => $this->toBool($output['disposition_default'] ?? false),
                'disposition_forced' => $this->toBool($output['disposition_forced'] ?? false),
                'label' => (string) ($output['label'] ?? $analysis['file_name']),
            ];
        }

        return $sanitized;
    }

    private function findStream(array $streams, int $streamIndex): ?array
    {
        foreach ($streams as $stream) {
            if ((int) ($stream['stream_index'] ?? -1) === $streamIndex) {
                return $stream;
            }
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
