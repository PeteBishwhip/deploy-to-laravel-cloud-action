<?php

declare(strict_types=1);

return [
    'name' => 'Laravel Cloud Deploy Action',
    'env' => 'production',
    'debug' => false,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => 'base64:0000000000000000000000000000000000000000000=',
    'cipher' => 'AES-256-CBC',
    'maintenance' => [
        'driver' => 'file',
    ],
    'providers' => [
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Console\ConsoleServiceProvider::class,
    ],
    'aliases' => [],
];
