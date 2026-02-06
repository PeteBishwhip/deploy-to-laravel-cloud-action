<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Support;

class Output
{
    public function setOutput(string $name, string $value): void
    {
        $outputPath = getenv('GITHUB_OUTPUT');
        if ($outputPath === false || $outputPath === '') {
            return;
        }
        file_put_contents($outputPath, $name.'='.$value."\n", FILE_APPEND);
    }

    public function summary(string $line): void
    {
        $summaryPath = getenv('GITHUB_STEP_SUMMARY');
        if ($summaryPath === false || $summaryPath === '') {
            return;
        }
        file_put_contents($summaryPath, $line."\n", FILE_APPEND);
    }

    public function fail(string $message, string $status = 'error', int $exitCode = 1, ?string $raw = null): void
    {
        $this->setOutput('deployment_status', $status);
        $this->setOutput('success', 'false');
        $this->summary("Deployment error: {$message}");
        fwrite(STDERR, $message."\n");
        if ($raw !== null && $raw !== '') {
            fwrite(STDERR, $raw."\n");
        }
        exit($exitCode);
    }
}
