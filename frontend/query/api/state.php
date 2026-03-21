<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();
    $bootstrap = api_bootstrap_payload($runtime);
    $jobs = $runtime->repository()->listJobSummaries(50);
    $worker = api_worker_state($runtime, $jobs);

    Http::json([
        'success' => true,
        ...$bootstrap,
        'jobs' => $jobs,
        'batches' => $runtime->repository()->listBatchSummaries(20),
        'worker' => $worker,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
