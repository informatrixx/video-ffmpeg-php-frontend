<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    Http::requireMethod('GET');
    $runtime = api_runtime();
    $auth = $runtime->auth();
    Http::requireAuth($auth);
    $auth->releaseSession();

    Http::json([
        'success' => true,
        'browser' => $runtime->browser()->browse(
            isset($_GET['folder']) ? (string) $_GET['folder'] : null,
            isset($_GET['active_source_path']) ? (string) $_GET['active_source_path'] : null
        ),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
