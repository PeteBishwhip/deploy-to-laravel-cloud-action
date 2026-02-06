<?php

declare(strict_types=1);

define('LARAVEL_CLOUD_DEPLOY_TEST', true);

require __DIR__ . '/../scripts/deploy.php';

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
    }
}

assert_same('https://example.test/deployments/1', normalize_link('https://example.test/deployments/1'), 'normalize_link string');
assert_same('https://example.test/deployments/2', normalize_link(['href' => 'https://example.test/deployments/2']), 'normalize_link array href');
assert_same(null, normalize_link(['rel' => 'self']), 'normalize_link missing href');
assert_same(null, normalize_link(null), 'normalize_link null');

if ($failures > 0) {
    fwrite(STDERR, "Tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
