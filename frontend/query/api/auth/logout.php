<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);
    $runtime->auth()->logout();

    Http::json([
        'success' => true,
        'authenticated' => false,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
