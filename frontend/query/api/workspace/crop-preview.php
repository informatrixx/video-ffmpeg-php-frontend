<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\CropPreviewService;
use App\Http;

try {
    Http::requireMethod('GET');
    $runtime = api_runtime();
    $auth = $runtime->auth();
    Http::requireAuth($auth);
    $auth->releaseSession();

    $filePath = (string) ($_GET['file'] ?? $_GET['source_path'] ?? '');
    if ($filePath === '') {
        throw new RuntimeException('Missing file');
    }

    $seek = isset($_GET['seek']) && is_numeric($_GET['seek']) ? (float) $_GET['seek'] : 0.0;
    $sar = isset($_GET['sar']) && is_numeric($_GET['sar']) ? (float) $_GET['sar'] : null;

    $service = new CropPreviewService($runtime->config(), $runtime->paths());
    $preview = $service->render($filePath, $seek, $sar);

    Http::json([
        'success' => true,
        'preview' => $preview,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
