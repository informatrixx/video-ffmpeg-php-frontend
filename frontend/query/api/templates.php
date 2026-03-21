<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Http::json([
            'success' => true,
            'templates' => $runtime->repository()->listTemplates(),
        ]);
    }

    Http::requireMethod('POST');
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);

    $template = $runtime->repository()->createTemplate([
        'name' => $payload['name'] ?? 'Unnamed template',
        'slug' => $payload['slug'] ?? null,
        'description' => $payload['description'] ?? '',
        'schema' => $payload['schema'] ?? null,
        'publish' => !empty($payload['publish']),
    ]);

    Http::json([
        'success' => true,
        'template' => $template,
    ], 201);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
