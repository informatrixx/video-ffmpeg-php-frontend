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

    $batchId = (int) ($payload['id'] ?? 0);
    $action = (string) ($payload['action'] ?? '');
    if ($batchId <= 0 || $action === '') {
        throw new RuntimeException('Missing batch id or action');
    }

    $batch = $runtime->repository()->getBatch($batchId);
    if ($batch === null) {
        throw new RuntimeException('Batch not found');
    }

    if ($action === 'delete') {
        $runtime->repository()->deleteBatch($batchId);
        Http::json([
            'success' => true,
            'batch' => null,
        ]);
        return;
    }

    foreach ($batch['jobs'] as $job) {
        if ($action === 'cancel') {
            if (in_array($job['status'], ['queued', 'ready', 'running', 'paused'], true)) {
                $runtime->repository()->performJobAction($job['id'], 'cancel');
            }
            continue;
        }

        if ($action === 'retry_failed') {
            if (in_array($job['status'], ['failed', 'cancelled'], true)) {
                $runtime->repository()->performJobAction($job['id'], 'retry');
            }
            continue;
        }

        throw new RuntimeException("Unsupported batch action: {$action}");
    }

    Http::json([
        'success' => true,
        'batch' => $runtime->repository()->getBatch($batchId),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
