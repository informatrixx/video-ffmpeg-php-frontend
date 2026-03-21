<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

use App\Http;

try {
    $runtime = api_runtime();
    $auth = $runtime->auth();
    if ($auth->configured()) {
        Http::requireAuth($auth);
    }
    $auth->releaseSession();
    Http::sseHeaders();

    $afterId = (int) ($_GET['after'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));
    $scope = (string) ($_GET['scope'] ?? 'dashboard');
    $timeoutAt = time() + 15;

    while (time() <= $timeoutAt) {
        $events = $runtime->repository()->listRuntimeEventsAfter($afterId, 100, $scope);
        if ($events !== []) {
            foreach ($events as $event) {
                $afterId = (int) $event['id'];
                Http::sseEvent($afterId, (string) $event['type'], $event);
            }
            exit;
        }

        Http::sseEvent($afterId, 'idle', [
            'after' => $afterId,
            'scope' => $scope,
        ]);
        sleep(2);
    }
} catch (Throwable $throwable) {
    Http::sseHeaders();
    Http::sseEvent(0, 'error', [
        'message' => $throwable->getMessage(),
    ]);
}
