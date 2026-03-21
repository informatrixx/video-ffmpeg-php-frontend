<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    $auth = $runtime->auth();
    Http::requireAuth($auth);

    $payload = Http::jsonBody();
    Http::requireCsrf($auth, $payload['csrf_token'] ?? null);
    $auth->releaseSession();

    $sourcePath = (string) ($payload['source_path'] ?? $payload['primary_source_path'] ?? '');
    if ($sourcePath === '') {
        throw new RuntimeException('Missing source_path');
    }

    $sourcePath = $runtime->paths()->assertInputFile($sourcePath);
    $type = (string) ($payload['type'] ?? ($runtime->paths()->guessType($sourcePath) ?? 'video'));

    $data = match ($type) {
        'rar' => $runtime->workspace()->buildArchiveWorkspace($sourcePath),
        default => $runtime->workspace()->buildVideoWorkspace(
            $sourcePath,
            is_array($payload['joined_sources'] ?? null) ? $payload['joined_sources'] : [],
            isset($payload['template_id']) ? (int) $payload['template_id'] : null,
            isset($payload['template_version_id']) ? (int) $payload['template_version_id'] : null,
            isset($payload['variant']) ? trim((string) $payload['variant']) : null,
            isset($payload['output_folder']) ? (string) $payload['output_folder'] : null
        ),
    };

    Http::json([
        'success' => true,
        'workspace' => $data,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
