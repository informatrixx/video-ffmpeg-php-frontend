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
        'browser' => $runtime->browser()->browseOutputFolders(
            isset($_GET['folder']) ? (string) $_GET['folder'] : null
        ),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
