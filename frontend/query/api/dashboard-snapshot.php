<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();
    $auth = $runtime->auth();
    if ($auth->configured()) {
        Http::requireAuth($auth);
    }
    $auth->releaseSession();

    $jobsLimit = max(1, min(200, (int) ($_GET['jobs_limit'] ?? 50)));
    $batchesLimit = max(1, min(100, (int) ($_GET['batches_limit'] ?? 10)));
    $jobs = $runtime->repository()->listJobSummaries($jobsLimit);

    Http::json([
        'success' => true,
        'worker' => api_worker_state($runtime, $jobs),
        'jobs' => $jobs,
        'batches' => $runtime->repository()->listBatchSummaries($batchesLimit),
        'last_event_id' => $runtime->repository()->latestRuntimeEventId(),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
