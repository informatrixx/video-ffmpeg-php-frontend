#!/usr/bin/php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/app/bootstrap.php';

$runtime = app_runtime();

if ($runtime->config()->debug()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    fwrite(STDOUT, <<<TXT
SQLite Queue Worker

Usage:
  php bin/runtime-worker.php
  php bin/runtime-worker.php --once

TXT);
    exit(0);
}

$worker = new App\QueueWorker($runtime);
$worker->run(in_array('--once', $arguments, true));
