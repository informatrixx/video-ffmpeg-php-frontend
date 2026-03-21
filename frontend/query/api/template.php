<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $templateId = (int) ($_GET['id'] ?? 0);
        if ($templateId <= 0) {
            throw new RuntimeException('Missing template id');
        }

        $template = $runtime->repository()->getTemplate($templateId);
        if ($template === null) {
            Http::json([
                'success' => false,
                'error' => 'Template not found',
            ], 404);
        }

        Http::json([
            'success' => true,
            'template' => $template,
        ]);
    }

    Http::requireMethod('POST');
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);

    $templateId = (int) ($payload['id'] ?? 0);
    if ($templateId <= 0) {
        throw new RuntimeException('Missing template id');
    }

    if (($payload['action'] ?? '') === 'publish') {
        $template = $runtime->repository()->publishTemplate(
            $templateId,
            (int) ($payload['version_id'] ?? 0)
        );
    } else {
        $template = $runtime->repository()->updateTemplate($templateId, [
            'name' => $payload['name'] ?? null,
            'description' => $payload['description'] ?? null,
            'schema' => $payload['schema'] ?? null,
            'publish' => !empty($payload['publish']),
        ]);
    }

    Http::json([
        'success' => true,
        'template' => $template,
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
