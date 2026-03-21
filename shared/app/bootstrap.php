<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', rtrim(dirname(__DIR__, 2), '/') . '/');
}

spl_autoload_register(static function (string $className): void {
    if (!str_starts_with($className, 'App\\')) {
        return;
    }

    $relativeName = substr($className, 4);
    $fileName = __DIR__ . '/' . str_replace('\\', '/', $relativeName) . '.php';

    if (is_file($fileName)) {
        require_once $fileName;
    }
});

function app_runtime(): App\Runtime
{
    static $runtime = null;

    if ($runtime === null) {
        $runtime = App\Runtime::boot();
    }

    return $runtime;
}
