<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    Http::requireMethod('GET');
    $runtime = api_runtime();
    $jobId = (string) ($_GET['id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Missing job id');
    }

    $job = $runtime->repository()->getJob($jobId);
    if ($job === null) {
        Http::json([
            'success' => false,
            'error' => 'Job not found',
        ], 404);
    }

    Http::json([
        'success' => true,
        'job' => $job,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
