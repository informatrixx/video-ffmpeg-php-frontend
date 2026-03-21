<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/app/bootstrap.php';

use App\Http;
use App\Runtime;

$runtime = app_runtime();

if ($runtime->config()->debug()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

function api_runtime(): Runtime
{
    return app_runtime();
}

function api_template_selection(Runtime $runtime, ?int $templateId, ?int $versionId): array
{
    $selected = $runtime->repository()->getPublishedTemplate($templateId);
    if ($versionId === null) {
        return $selected;
    }

    foreach ($selected['template']['versions'] as $version) {
        if ((int) $version['id'] === $versionId) {
            return [
                'template' => $selected['template'],
                'version' => $version,
            ];
        }
    }

    throw new RuntimeException('Template version not found');
}

function api_bootstrap_payload(Runtime $runtime): array
{
    $auth = $runtime->auth();
    $payload = [
        'auth' => [
            'configured' => $auth->configured(),
            'authenticated' => $auth->isAuthenticated(),
            'csrf_token' => $auth->csrfToken(),
        ],
        'roots' => [
            'input' => $runtime->config()->inputRoots(),
            'output' => $runtime->config()->outputRoots(),
        ],
        'editor_options' => $runtime->workspace()->editorOptions(),
        'templates' => $runtime->repository()->listTemplates(),
        'last_event_id' => $runtime->repository()->latestRuntimeEventId(),
    ];
    $auth->releaseSession();

    return $payload;
}

function api_finalize_plan(Runtime $runtime, array $analysis, array $plan, ?string $outputFolder = null): array
{
    if ($outputFolder !== null) {
        $plan['job']['output_folder'] = $runtime->paths()->assertOutputFolder($outputFolder);
    }

    $plan['job']['title'] = trim((string) ($plan['job']['title'] ?? ($analysis['info']['title'] ?? $analysis['base_name'] ?? '')));
    $plan['job']['output_file'] = $runtime->paths()->normalizeOutputFileName(
        $plan['job']['output_file'] ?? null,
        $analysis['base_name'] ?? 'output'
    );
    $plan['resolved_names'] = api_resolved_names($analysis, $plan);
    $plan['validation_messages'] = api_plan_validation_messages($runtime, $analysis, $plan);

    return $plan;
}

function api_worker_state(Runtime $runtime, ?array $jobs = null): array
{
    $worker = $runtime->repository()->getSetting('runtime_worker_state', [
        'state' => 'unknown',
        'updated_at' => null,
    ]);

    $updatedAt = isset($worker['updated_at']) ? strtotime((string) $worker['updated_at']) : false;
    $ageSeconds = is_int($updatedAt) && $updatedAt > 0 ? max(0, time() - $updatedAt) : null;
    $staleAfter = max(
        45,
        (int) ceil(max($runtime->config()->workerIdleSleepMs(), $runtime->config()->workerPollIntervalMs()) / 1000) * 8
    );

    $worker['updated_age_seconds'] = $ageSeconds;
    $worker['stale_after_seconds'] = $staleAfter;
    $worker['stale'] = $ageSeconds !== null ? ($ageSeconds >= $staleAfter) : true;

    $jobs = $jobs ?? $runtime->repository()->listJobSummaries(50);
    $activeHeartbeats = array_filter($jobs, static fn (array $job): bool => in_array($job['status'] ?? '', ['running', 'paused', 'cancel_requested'], true));
    foreach ($activeHeartbeats as $job) {
        $heartbeatAt = isset($job['last_heartbeat_at']) ? strtotime((string) $job['last_heartbeat_at']) : false;
        if (!is_int($heartbeatAt) || $heartbeatAt <= 0) {
            continue;
        }

        $jobAgeSeconds = max(0, time() - $heartbeatAt);
        if ($jobAgeSeconds < $staleAfter) {
            $worker['stale'] = false;
            $worker['updated_age_seconds'] = $jobAgeSeconds;
            $worker['heartbeat_source'] = 'job';
            break;
        }
    }

    return $worker;
}

function api_resolved_names(array $analysis, array $plan): array
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

function api_plan_validation_messages(Runtime $runtime, array $analysis, array $plan): array
{
    $messages = [];
    $resolved = api_resolved_names($analysis, $plan);

    if ($resolved['title'] === '') {
        $messages[] = api_validation_message('warning', 'job_title_empty', 'Der Job-Titel ist leer und wird zur Laufzeit aus dem Dateinamen abgeleitet.');
    }

    if ($resolved['output_file'] === '') {
        $messages[] = api_validation_message('error', 'output_file_empty', 'Keine gültige Zieldatei gesetzt.');
    }

    if (($analysis['type'] ?? 'video') === 'video') {
        $enabledVideoOutputs = api_enabled_outputs($plan['video_outputs'] ?? []);
        if ($enabledVideoOutputs === 0) {
            $messages[] = api_validation_message('error', 'video_output_missing', 'Mindestens ein Video-Output muss aktiv bleiben.');
        } elseif ($enabledVideoOutputs > 1) {
            $messages[] = api_validation_message('error', 'video_output_multiple', 'Es darf genau ein aktiver Video-Output vorhanden sein.');
        }

        if (api_enabled_outputs($plan['audio_outputs'] ?? []) === 0) {
            $messages[] = api_validation_message('warning', 'audio_output_missing', 'Es ist aktuell keine Audio-Spur aktiv.');
        }
    }

    $outputPath = $resolved['output_path'];
    if ($outputPath !== '' && file_exists($outputPath)) {
        $strategy = $runtime->config()->outputFileExistsStrategy();
        $messages[] = match ($strategy) {
            'overwrite' => api_validation_message('warning', 'output_exists_overwrite', 'Die Zieldatei existiert bereits und wird ueberschrieben.'),
            'error' => api_validation_message('error', 'output_exists_error', 'Die Zieldatei existiert bereits und der Job wird so fehlschlagen.'),
            default => api_validation_message('warning', 'output_exists_move', 'Die Zieldatei existiert bereits. Die Runtime wird einen neuen Dateinamen vergeben.'),
        };
    }

    return $messages;
}

function api_enabled_outputs(array $outputs): int
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

function api_validation_message(string $level, string $code, string $message): array
{
    return [
        'level' => $level,
        'code' => $code,
        'message' => $message,
    ];
}

function api_handle_exception(Throwable $throwable): void
{
    $statusCode = 500;
    $message = 'Internal server error';

    if ($throwable instanceof RuntimeException || $throwable instanceof InvalidArgumentException) {
        $statusCode = 400;
        $message = $throwable->getMessage();
    } elseif (api_runtime()->config()->debug()) {
        $message = $throwable->getMessage();
    }

    Http::json([
        'success' => false,
        'error' => $message,
    ], $statusCode);
}
