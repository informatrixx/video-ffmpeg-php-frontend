<?php

declare(strict_types=1);

namespace App;

final class AuthService
{
    private const SESSION_USER_KEY = 'app_auth_user';
    private const SESSION_CSRF_KEY = 'app_csrf_token';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;

        if (PHP_SAPI !== 'cli') {
            $this->startSession();
        }
    }

    public function configured(): bool
    {
        return $this->config->authConfigured();
    }

    public function isAuthenticated(): bool
    {
        if (!$this->configured()) {
            return true;
        }

        return isset($_SESSION[self::SESSION_USER_KEY]);
    }

    public function login(string $password): bool
    {
        $hash = $this->config->adminPasswordHash();
        if ($hash === null) {
            return false;
        }

        if (!password_verify($password, $hash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_KEY] = 'admin';
        $this->csrfToken();

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_USER_KEY], $_SESSION[self::SESSION_CSRF_KEY]);
        session_regenerate_id(true);
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION[self::SESSION_CSRF_KEY])) {
            $_SESSION[self::SESSION_CSRF_KEY] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION[self::SESSION_CSRF_KEY];
    }

    public function verifyCsrf(?string $token): bool
    {
        if (!$this->configured()) {
            return true;
        }

        return is_string($token) && hash_equals($this->csrfToken(), $token);
    }

    public function releaseSession(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($this->config->sessionName());
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}
