<?php

declare(strict_types=1);

require __DIR__ . '/../app/Support/UrlHelper.php';

use App\Support\UrlHelper;

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
    }
}

assert_same('https://example.test/deployments/1', UrlHelper::normalizeLink('https://example.test/deployments/1'), 'normalizeLink string');
assert_same('https://example.test/deployments/2', UrlHelper::normalizeLink(['href' => 'https://example.test/deployments/2']), 'normalizeLink array href');
assert_same(null, UrlHelper::normalizeLink(['rel' => 'self']), 'normalizeLink missing href');
assert_same(null, UrlHelper::normalizeLink(null), 'normalizeLink null');

assert_same('https://example.test', UrlHelper::buildEnvironmentUrl('example.test', null), 'buildEnvironmentUrl vanity domain');
assert_same('https://example.test', UrlHelper::buildEnvironmentUrl('https://example.test', null), 'buildEnvironmentUrl vanity url');
assert_same('http://example.test', UrlHelper::buildEnvironmentUrl('http://example.test', null), 'buildEnvironmentUrl vanity http');
assert_same('https://api.example.test/environments/1', UrlHelper::buildEnvironmentUrl(null, 'https://api.example.test/environments/1'), 'buildEnvironmentUrl api link');
assert_same(null, UrlHelper::buildEnvironmentUrl(null, null), 'buildEnvironmentUrl null');

if ($failures > 0) {
    fwrite(STDERR, "Tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
