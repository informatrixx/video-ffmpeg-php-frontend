<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

use App\Http;

try {
    $runtime = api_runtime();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Http::json([
            'success' => true,
            'jobs' => $runtime->repository()->listJobSummaries(),
        ]);
    }

    Http::requireMethod('POST');
    Http::requireAuth($runtime->auth());

    $payload = Http::jsonBody();
    Http::requireCsrf($runtime->auth(), $payload['csrf_token'] ?? null);

    $sourcePath = $runtime->paths()->assertInputFile((string) ($payload['source_path'] ?? ''));
    $outputFolder = $runtime->paths()->assertWritableOutputFolder((string) ($payload['output_folder'] ?? ''));
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
        array_merge(
            $payload['overrides'] ?? [],
            ['output_folder' => $outputFolder]
        )
    );
    $plan = api_finalize_plan($runtime, $analysis, $plan, $outputFolder);

    $job = $runtime->repository()->createJob([
        'type' => $analysis['type'],
        'source_path' => $sourcePath,
        'output_folder' => $outputFolder,
        'output_file' => $plan['job']['output_file'],
        'title' => $plan['job']['title'],
        'status' => 'queued',
        'template_id' => (int) $selection['template']['id'],
        'template_version_id' => (int) $selection['version']['id'],
        'analysis' => $analysis,
        'compiled_plan' => $plan,
    ]);

    Http::json([
        'success' => true,
        'job' => $job,
    ], 201);
} catch (Throwable $throwable) {
    api_handle_exception($throwable);
}
