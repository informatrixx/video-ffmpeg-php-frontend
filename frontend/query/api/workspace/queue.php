<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);

    $type = (string) ($payload['type'] ?? ($payload['plan']['type'] ?? 'video'));
    $job = match ($type) {
        'rar' => $runtime->workspace()->queueArchiveJob($payload),
        default => $runtime->workspace()->queueVideoJob($payload),
    };

    Http::json([
        'success' => true,
        'job' => $job,
    ], 201);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
