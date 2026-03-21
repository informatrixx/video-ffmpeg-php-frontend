<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    $payload = Http::jsonBody();

    if (!$runtime->auth()->configured()) {
        Http::json([
            'success' => false,
            'error' => 'Authentication is not configured yet',
        ], 400);
    }

    $ok = $runtime->auth()->login((string) ($payload['password'] ?? ''));
    if (!$ok) {
        Http::json([
            'success' => false,
            'error' => 'Invalid credentials',
        ], 401);
    }

    Http::json([
        'success' => true,
        'configured' => true,
        'authenticated' => true,
        'csrf_token' => $runtime->auth()->csrfToken(),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
