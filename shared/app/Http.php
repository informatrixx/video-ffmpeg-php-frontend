<?php

declare(strict_types=1);

namespace App;

final class Http
{
    public static function requireMethod(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
            self::json([
                'success' => false,
                'error' => "Method not allowed. Expected {$method}.",
            ], 405);
        }
    }

    public static function jsonBody(): array
    {
        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '') {
            return [];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            self::json([
                'success' => false,
                'error' => 'Invalid JSON body',
            ], 400);
        }

        return $payload;
    }

    public static function requireAuth(AuthService $auth): void
    {
        if (!$auth->isAuthenticated()) {
            self::json([
                'success' => false,
                'error' => 'Authentication required',
            ], 401);
        }
    }

    public static function requireCsrf(AuthService $auth, ?string $token): void
    {
        if (!$auth->verifyCsrf($token)) {
            self::json([
                'success' => false,
                'error' => 'Invalid CSRF token',
            ], 403);
        }
    }

    public static function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function sseHeaders(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    public static function sseEvent(int $id, string $topic, array $payload): void
    {
        echo 'id: ' . $id . "\n";
        echo 'event: ' . $topic . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        flush();
    }
}
