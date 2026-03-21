<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    Http::requireMethod('POST');
    $runtime = api_runtime();
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);

    $sourcePath = $runtime->paths()->assertInputFile((string) ($payload['source_path'] ?? ''));
    $selection = api_template_selection(
        $runtime,
        isset($payload['template_id']) ? (int) $payload['template_id'] : null,
        isset($payload['template_version_id']) ? (int) $payload['template_version_id'] : null
    );

    $analysis = $runtime->analyzer()->analyze($sourcePath);
    $plan = $runtime->templateEngine()->compile(
        $analysis,
        $selection['version']['schema'],
        isset($payload['variant']) ? (string) $payload['variant'] : null,
        $payload['overrides'] ?? []
    );
    $plan = api_finalize_plan(
        $runtime,
        $analysis,
        $plan,
        isset(($payload['overrides'] ?? [])['output_folder']) ? (string) ($payload['overrides']['output_folder']) : null
    );

    Http::json([
        'success' => true,
        'analysis' => $analysis,
        'plan' => $plan,
        'template' => [
            'id' => (int) $selection['template']['id'],
            'version_id' => (int) $selection['version']['id'],
            'name' => $selection['template']['name'],
            'version' => (int) $selection['version']['version'],
        ],
    ]);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
