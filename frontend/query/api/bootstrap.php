<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();

    Http::json([
        'success' => true,
        ...api_bootstrap_payload($runtime),
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
