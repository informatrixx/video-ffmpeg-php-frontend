<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    $auth = $runtime->auth();
    Http::requireAuth($auth);

    $payload = Http::jsonBody();
    Http::requireCsrf($auth, $payload['csrf_token'] ?? null);
    $auth->releaseSession();

    $parentPath = (string) ($payload['parent_path'] ?? '');
    $folderName = (string) ($payload['folder_name'] ?? '');
    if ($parentPath === '') {
        throw new RuntimeException('Missing parent_path');
    }

    $folderPath = $runtime->paths()->createOutputFolder($parentPath, $folderName);

    Http::json([
        'success' => true,
        'folder_path' => $folderPath,
        'browser' => $runtime->browser()->browseOutputFolders($folderPath),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
