<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Http::json([
            'success' => true,
            'batches' => $runtime->repository()->listBatchSummaries(),
        ]);
    }

    Http::requireMethod('POST');
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);
    $selection = api_template_selection(
        $runtime,
        isset($payload['template_id']) ? (int) $payload['template_id'] : null,
        isset($payload['template_version_id']) ? (int) $payload['template_version_id'] : null
    );
    $sourceFolder = $runtime->paths()->assertInputFolder((string) ($payload['source_folder'] ?? ''));
    $batch = $runtime->batch()->create(
        (string) ($payload['label'] ?? ('Batch ' . basename($sourceFolder))),
        $sourceFolder,
        !empty($payload['recursive']),
        (string) ($payload['output_folder'] ?? ''),
        $selection,
        isset($payload['variant']) ? (string) $payload['variant'] : null,
        is_array($payload['items'] ?? null) ? $payload['items'] : []
    );

    Http::json([
        'success' => true,
        'batch' => $batch,
    ], 201);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
