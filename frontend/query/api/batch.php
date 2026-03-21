<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    Http::requireMethod('GET');
    $runtime = api_runtime();
    $batchId = (int) ($_GET['id'] ?? 0);
    if ($batchId <= 0) {
        throw new RuntimeException('Missing batch id');
    }

    $batch = $runtime->repository()->getBatch($batchId);
    if ($batch === null) {
        Http::json([
            'success' => false,
            'error' => 'Batch not found',
        ], 404);
    }

    Http::json([
        'success' => true,
        'batch' => $batch,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
