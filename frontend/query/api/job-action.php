<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);

    $jobId = (string) ($payload['id'] ?? '');
    $action = (string) ($payload['action'] ?? '');
    if ($jobId === '' || $action === '') {
        throw new RuntimeException('Missing job id or action');
    }

    $job = $runtime->repository()->performJobAction($jobId, $action, $payload);

    Http::json([
        'success' => true,
        'job' => $job,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
