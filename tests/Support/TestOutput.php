<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Tests\Support;

use LaravelCloudDeploy\Support\Output;
use RuntimeException;

class TestOutput extends Output
{
    public function fail(string $message, string $status = 'error', int $exitCode = 1, ?string $raw = null): void
    {
        throw new RuntimeException($message, $exitCode);
    }
}
