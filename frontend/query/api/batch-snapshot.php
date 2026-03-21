<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();
    $auth = $runtime->auth();
    Http::requireAuth($auth);
    $auth->releaseSession();

    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
    $batches = $runtime->repository()->listBatchSummaries($limit, $beforeId);
    $nextBeforeId = null;
    if (count($batches) === $limit) {
        $last = end($batches);
        $nextBeforeId = is_array($last) ? (int) ($last['id'] ?? 0) : null;
    }
    $jobs = $runtime->repository()->listJobSummaries(50);

    Http::json([
        'success' => true,
        'worker' => api_worker_state($runtime, $jobs),
        'batches' => $batches,
        'next_before_id' => $nextBeforeId,
        'last_event_id' => $runtime->repository()->latestRuntimeEventId(),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
