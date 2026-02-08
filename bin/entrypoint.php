#!/usr/bin/env php
<?php

declare(strict_types=1);

$cwd = getcwd();
if (is_string($cwd) && $cwd !== '') {
    fwrite(STDOUT, "[entrypoint] cwd={$cwd}\n");
}

$logPath = null;
$workspace = getenv('GITHUB_WORKSPACE');
if (is_string($workspace) && $workspace !== '') {
    $logPath = $workspace . '/laravel.log';
}

if ($logPath === null && is_string($cwd) && $cwd !== '') {
    $logPath = $cwd . '/laravel.log';
}

if ($logPath !== null) {
    $_ENV['LOG_PATH'] = $_ENV['LOG_PATH'] ?? $logPath;
    $_SERVER['LOG_PATH'] = $_SERVER['LOG_PATH'] ?? $_ENV['LOG_PATH'];
    putenv('LOG_PATH=' . $_ENV['LOG_PATH']);
}

// Force stdout logging in PHAR runtime.
$_ENV['LOG_CHANNEL'] = $_ENV['LOG_CHANNEL'] ?? 'stdout';
$_ENV['LOG_STACK'] = $_ENV['LOG_STACK'] ?? 'stdout';
$_SERVER['LOG_CHANNEL'] = $_SERVER['LOG_CHANNEL'] ?? $_ENV['LOG_CHANNEL'];
$_SERVER['LOG_STACK'] = $_SERVER['LOG_STACK'] ?? $_ENV['LOG_STACK'];
putenv('LOG_CHANNEL=' . $_ENV['LOG_CHANNEL']);
putenv('LOG_STACK=' . $_ENV['LOG_STACK']);

$artisan = __DIR__ . '/../artisan';
if (!file_exists($artisan)) {
    $phar = Phar::running(false);
    if (is_string($phar) && $phar !== '') {
        $artisan = 'phar://' . $phar . '/artisan';
    }
}

if (!file_exists($artisan)) {
    fwrite(STDERR, "[entrypoint] artisan not found\n");
    exit(1);
}

$argv[0] = $artisan;
require $artisan;
