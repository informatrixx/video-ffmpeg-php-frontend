<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    $auth = $runtime->auth();
    Http::requireAuth($auth);

    $payload = Http::jsonBody();
    Http::requireCsrf($auth, $payload['csrf_token'] ?? null);
    $auth->releaseSession();

    $selection = api_template_selection(
        $runtime,
        isset($payload['template_id']) ? (int) $payload['template_id'] : null,
        isset($payload['template_version_id']) ? (int) $payload['template_version_id'] : null
    );
    $preview = $runtime->batch()->preview(
        (string) ($payload['source_folder'] ?? ''),
        !empty($payload['recursive']),
        (string) ($payload['output_folder'] ?? ''),
        $selection,
        isset($payload['variant']) ? (string) $payload['variant'] : null,
        is_array($payload['items'] ?? null) ? $payload['items'] : []
    );
    $previewItems = array_map(
        static function (array $item): array {
            unset($item['analysis_full'], $item['compiled_plan']);
            return $item;
        },
        $preview['items']
    );

    Http::json([
        'success' => true,
        'items' => $previewItems,
        'source_folder' => $preview['source_folder'],
        'recursive' => $preview['recursive'],
        'output_folder' => $preview['output_folder'],
        'template' => [
            'id' => (int) $selection['template']['id'],
            'version_id' => (int) $selection['version']['id'],
            'name' => $selection['template']['name'],
        ],
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
