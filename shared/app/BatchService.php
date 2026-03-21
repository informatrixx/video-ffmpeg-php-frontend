<?php

declare(strict_types=1);

namespace App;

final class BatchService
{
    private PathService $paths;
    private MediaAnalyzer $analyzer;
    private TemplateEngine $templateEngine;
    private Repository $repository;
    private WorkspaceService $workspace;

    public function __construct(
        PathService $paths,
        MediaAnalyzer $analyzer,
        TemplateEngine $templateEngine,
        Repository $repository,
        WorkspaceService $workspace
    ) {
        $this->paths = $paths;
        $this->analyzer = $analyzer;
        $this->templateEngine = $templateEngine;
        $this->repository = $repository;
        $this->workspace = $workspace;
    }

    public function preview(
        string $sourceFolder,
        bool $recursive,
        string $outputFolder,
        array $selection,
        ?string $variant,
        array $items = []
    ): array {
        $sourceFolder = $this->paths->assertInputFolder($sourceFolder);
        $outputFolder = $this->paths->assertOutputFolderPath($outputFolder);
        $items = $this->normalizeItems($sourceFolder, $recursive, $items);

        $previewItems = [];
        foreach ($items as $item) {
            $previewItems[] = $this->buildPreviewItem(
                $sourceFolder,
                $recursive,
                $outputFolder,
                $selection,
                $variant,
                $item
            );
        }

        return [
            'source_folder' => $sourceFolder,
            'recursive' => $recursive,
            'output_folder' => $outputFolder,
            'items' => $previewItems,
        ];
    }

    public function create(
        string $label,
        string $sourceFolder,
        bool $recursive,
        string $outputFolder,
        array $selection,
        ?string $variant,
        array $items = []
    ): array {
        $preview = $this->preview($sourceFolder, $recursive, $outputFolder, $selection, $variant, $items);
        $jobPayloads = [];

        foreach ($preview['items'] as $item) {
            if (!$item['enabled']) {
                continue;
            }

            if ($this->hasValidationLevel($item['validation_messages'], 'error')) {
                throw new \RuntimeException('Mindestens ein aktiver Batch-Eintrag enthaelt noch Fehler.');
            }

            $itemOutputFolder = $this->paths->ensureWritableOutputFolder((string) $item['output_folder']);
            $this->workspace->rememberOutputFolder($itemOutputFolder);
            $plan = $item['compiled_plan'];
            $plan['job']['output_folder'] = $itemOutputFolder;

            $jobPayloads[] = [
                'type' => $item['analysis_full']['type'] ?? 'video',
                'source_path' => $item['source_path'],
                'output_folder' => $itemOutputFolder,
                'output_file' => $plan['job']['output_file'],
                'title' => $plan['job']['title'],
                'status' => 'queued',
                'template_id' => (int) $selection['template']['id'],
                'template_version_id' => (int) $selection['version']['id'],
                'analysis' => $this->jobAnalysisPayload($item['analysis_full']),
                'compiled_plan' => $plan,
            ];
        }

        if ($jobPayloads === []) {
            throw new \RuntimeException('No valid batch candidates found');
        }

        $this->workspace->rememberOutputFolder($preview['output_folder']);

        return $this->repository->createBatch(
            $label,
            $preview['source_folder'],
            $preview['recursive'],
            $preview['output_folder'],
            (int) $selection['template']['id'],
            (int) $selection['version']['id'],
            $jobPayloads
        );
    }

    private function normalizeItems(string $sourceFolder, bool $recursive, array $items): array
    {
        if ($items === []) {
            $items = $this->paths->listBatchCandidates($sourceFolder, $recursive);
        }

        return array_values(array_filter(
            array_map(
                function (mixed $item) use ($sourceFolder, $recursive): ?array {
                    if (!is_array($item)) {
                        return null;
                    }

                    $normalized = $this->normalizeItem($sourceFolder, $recursive, $item);
                    return ($this->paths->guessType($normalized['source_path']) ?? '') === 'video'
                        ? $normalized
                        : null;
                },
                $items
            )
        ));
    }

    private function normalizeItem(string $sourceFolder, bool $recursive, array $item): array
    {
        $rawSourcePath = (string) ($item['source_path'] ?? $item['path'] ?? '');
        $relativeSourcePath = (string) ($item['relative_source_path'] ?? '');
        if ($relativeSourcePath === '') {
            $sourcePath = $this->paths->assertInputFile($rawSourcePath);
            $relativeSourcePath = $this->relativeSourcePath($sourceFolder, $sourcePath);
        } else {
            $sourcePath = $rawSourcePath !== '' ? $this->paths->assertInputFile($rawSourcePath) : ($sourceFolder . '/' . ltrim($relativeSourcePath, '/'));
            $sourcePath = $this->paths->assertInputFile($sourcePath);
        }

        $overrides = is_array($item['overrides'] ?? null) ? $item['overrides'] : [];
        $normalizedOverrides = [
            'title' => $this->toBool($overrides['title'] ?? false),
            'output_file' => $this->toBool($overrides['output_file'] ?? false),
            'output_folder' => $this->toBool($overrides['output_folder'] ?? false),
        ];

        return [
            'enabled' => !array_key_exists('enabled', $item) || $this->toBool($item['enabled']),
            'source_path' => $sourcePath,
            'relative_source_path' => $relativeSourcePath,
            'recursive' => $recursive,
            'title' => trim((string) ($item['title'] ?? '')),
            'output_file' => trim((string) ($item['output_file'] ?? '')),
            'output_folder' => trim((string) ($item['output_folder'] ?? '')),
            'overrides' => $normalizedOverrides,
        ];
    }

    private function buildPreviewItem(
        string $sourceFolder,
        bool $recursive,
        string $outputFolder,
        array $selection,
        ?string $variant,
        array $item
    ): array {
        try {
            $defaultOutputFolder = $this->defaultOutputFolder(
                $outputFolder,
                $item['relative_source_path'],
                $recursive
            );
            $effectiveOutputFolder = $item['overrides']['output_folder'] && $item['output_folder'] !== ''
                ? $this->paths->assertOutputFolderPath($item['output_folder'])
                : $defaultOutputFolder;

            if (($this->paths->guessType($item['source_path']) ?? '') !== 'video') {
                throw new \RuntimeException('Nur Video-Dateien koennen als Batch vorbereitet werden');
            }

            $workspace = $this->workspace->buildVideoWorkspace(
                $item['source_path'],
                [],
                (int) $selection['template']['id'],
                (int) $selection['version']['id'],
                $variant,
                $effectiveOutputFolder
            );
            $analysis = $workspace['analysis'];
            $plan = $workspace['plan'];
            if ($item['overrides']['title']) {
                $plan['job']['title'] = $item['title'];
            }
            if ($item['overrides']['output_file']) {
                $plan['job']['output_file'] = $item['output_file'];
            }
            $plan = $this->finalizePlan($analysis, $plan, $effectiveOutputFolder);
            $writeIssue = $this->paths->outputFolderWriteIssue($effectiveOutputFolder);
            if ($writeIssue !== null) {
                $plan['validation_messages'][] = $this->validationMessage('error', 'output_folder_not_writable', $writeIssue);
                $plan['validation_messages'] = $this->dedupeMessages($plan['validation_messages']);
            }

            return [
                'enabled' => $item['enabled'],
                'source_path' => $item['source_path'],
                'relative_source_path' => $item['relative_source_path'],
                'title' => $plan['job']['title'],
                'output_file' => $plan['job']['output_file'],
                'output_folder' => $plan['job']['output_folder'],
                'default_output_folder' => $defaultOutputFolder,
                'resolved_names' => $plan['resolved_names'],
                'validation_messages' => $plan['validation_messages'],
                'analysis' => [
                    'file_name' => $analysis['file_name'],
                    'duration_human' => $analysis['info']['duration_human'] ?? '',
                    'audio_streams' => count($analysis['streams']['audio'] ?? []),
                    'video_streams' => count($analysis['streams']['video'] ?? []),
                    'subtitle_streams' => count($analysis['streams']['subtitle'] ?? []),
                ],
                'plan_summary' => [
                    'video_outputs' => count($plan['video_outputs'] ?? []),
                    'audio_outputs' => count($plan['audio_outputs'] ?? []),
                    'subtitle_outputs' => count($plan['subtitle_outputs'] ?? []),
                ],
                'overrides' => $item['overrides'],
                'analysis_full' => $analysis,
                'compiled_plan' => $plan,
            ];
        } catch (\Throwable $throwable) {
            $fallbackOutputFolder = trim((string) ($item['output_folder'] ?? '')) !== ''
                ? (string) $item['output_folder']
                : $outputFolder;
            return [
                'enabled' => $item['enabled'],
                'source_path' => $item['source_path'],
                'relative_source_path' => $item['relative_source_path'],
                'title' => $item['title'],
                'output_file' => $item['output_file'],
                'output_folder' => $fallbackOutputFolder,
                'default_output_folder' => $fallbackOutputFolder,
                'resolved_names' => [
                    'title' => $item['title'],
                    'output_file' => $item['output_file'],
                    'output_folder' => $fallbackOutputFolder,
                    'output_path' => $fallbackOutputFolder !== ''
                        ? ($fallbackOutputFolder . '/' . $item['output_file'])
                        : $item['output_file'],
                ],
                'validation_messages' => [
                    $this->validationMessage('error', 'batch_item_invalid', $throwable->getMessage()),
                ],
                'analysis' => [
                    'file_name' => basename($item['source_path']),
                    'duration_human' => '',
                    'audio_streams' => 0,
                    'video_streams' => 0,
                    'subtitle_streams' => 0,
                ],
                'plan_summary' => [
                    'video_outputs' => 0,
                    'audio_outputs' => 0,
                    'subtitle_outputs' => 0,
                ],
                'overrides' => $item['overrides'],
                'analysis_full' => null,
                'compiled_plan' => null,
            ];
        }
    }

    private function finalizePlan(array $analysis, array $plan, string $outputFolder): array
    {
        $plan['job']['output_folder'] = $this->paths->assertOutputFolderPath($outputFolder);
        $plan['job']['title'] = trim((string) ($plan['job']['title'] ?? ($analysis['info']['title'] ?? $analysis['base_name'] ?? '')));
        $plan['job']['output_file'] = $this->paths->normalizeOutputFileName(
            $plan['job']['output_file'] ?? null,
            $analysis['base_name'] ?? 'output'
        );
        $plan['resolved_names'] = $this->resolvedNames($analysis, $plan);
        $plan['validation_messages'] = $this->validationMessages($analysis, $plan);

        return $plan;
    }

    private function resolvedNames(array $analysis, array $plan): array
    {
        $folder = rtrim((string) ($plan['job']['output_folder'] ?? dirname((string) ($analysis['file'] ?? ''))), '/');
        $file = (string) ($plan['job']['output_file'] ?? (($analysis['base_name'] ?? 'output') . '.mkv'));

        return [
            'title' => trim((string) ($plan['job']['title'] ?? ($analysis['info']['title'] ?? $analysis['base_name'] ?? ''))),
            'output_folder' => $folder,
            'output_file' => $file,
            'output_path' => $folder !== '' ? ($folder . '/' . $file) : $file,
        ];
    }

    private function validationMessages(array $analysis, array $plan): array
    {
        $messages = [];
        $resolved = $this->resolvedNames($analysis, $plan);

        if ($resolved['title'] === '') {
            $messages[] = $this->validationMessage('warning', 'job_title_empty', 'Der Job-Titel ist leer und wird zur Laufzeit aus dem Dateinamen abgeleitet.');
        }

        if ($resolved['output_file'] === '') {
            $messages[] = $this->validationMessage('error', 'output_file_empty', 'Keine gueltige Zieldatei gesetzt.');
        }

        $enabledVideoOutputs = $this->enabledOutputs($plan['video_outputs'] ?? []);
        if ($enabledVideoOutputs === 0) {
            $messages[] = $this->validationMessage('error', 'video_output_missing', 'Mindestens ein Video-Output muss aktiv bleiben.');
        } elseif ($enabledVideoOutputs > 1) {
            $messages[] = $this->validationMessage('error', 'video_output_multiple', 'Es darf genau ein aktiver Video-Output vorhanden sein.');
        }

        if ($this->enabledOutputs($plan['audio_outputs'] ?? []) === 0) {
            $messages[] = $this->validationMessage('warning', 'audio_output_missing', 'Es ist aktuell keine Audio-Spur aktiv.');
        }

        if ($resolved['output_path'] !== '' && file_exists($resolved['output_path'])) {
            $messages[] = $this->validationMessage('warning', 'output_exists', 'Die Zieldatei existiert bereits. Die Runtime wird die konfigurierten Konfliktregeln anwenden.');
        }

        return $this->dedupeMessages($messages);
    }

    private function enabledOutputs(array $outputs): int
    {
        $count = 0;
        foreach ($outputs as $output) {
            if (!is_array($output)) {
                continue;
            }

            $enabled = array_key_exists('enabled', $output)
                ? (bool) $output['enabled']
                : (($output['action'] ?? null) !== 'skip');

            if ($enabled && (($output['action'] ?? null) !== 'skip')) {
                $count++;
            }
        }

        return $count;
    }

    private function validationMessage(string $level, string $code, string $message): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function dedupeMessages(array $messages): array
    {
        $seen = [];
        $unique = [];
        foreach ($messages as $message) {
            $key = ($message['level'] ?? 'info') . '|' . ($message['code'] ?? '') . '|' . ($message['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $message;
        }

        return $unique;
    }

    private function defaultOutputFolder(string $outputFolder, string $relativeSourcePath, bool $recursive): string
    {
        if (!$recursive) {
            return $outputFolder;
        }

        $relativeDirectory = dirname($relativeSourcePath);
        if ($relativeDirectory === '' || $relativeDirectory === '.' || $relativeDirectory === '/') {
            return $outputFolder;
        }

        return $this->paths->assertOutputFolderPath(rtrim($outputFolder, '/') . '/' . trim($relativeDirectory, '/'));
    }

    private function relativeSourcePath(string $sourceFolder, string $sourcePath): string
    {
        if ($sourcePath === $sourceFolder) {
            return basename($sourcePath);
        }

        $relative = ltrim(substr($sourcePath, strlen(rtrim($sourceFolder, '/'))), '/');
        return $relative !== '' ? $relative : basename($sourcePath);
    }

    private function jobAnalysisPayload(?array $analysis): ?array
    {
        if (!is_array($analysis)) {
            return null;
        }

        return [
            'info' => $analysis['info'] ?? [],
            'primary' => $analysis,
            'joined_inputs' => [],
        ];
    }

    private function hasValidationLevel(array $messages, string $level): bool
    {
        foreach ($messages as $message) {
            if (($message['level'] ?? null) === $level) {
                return true;
            }
        }

        return false;
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
