<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    $runtime = api_runtime();

    Http::json([
        'success' => true,
        'configured' => $runtime->auth()->configured(),
        'authenticated' => $runtime->auth()->isAuthenticated(),
        'csrf_token' => $runtime->auth()->csrfToken(),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
