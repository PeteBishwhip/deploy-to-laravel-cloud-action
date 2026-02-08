<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Support;

enum ConsoleTone: string
{
    case Standard = 'standard';
    case Notice = 'notice';
}

class Console
{
    private bool $inActions;

    public function __construct(?bool $inActions = null)
    {
        $this->inActions = $inActions ?? (getenv('GITHUB_ACTIONS') === 'true');
    }

    public function writeln(string $message, ConsoleTone $tone = ConsoleTone::Standard, $stream = null): void
    {
        $stream = $stream ?? STDOUT;
        fwrite($stream, $this->format($message, $tone) . "\n");
    }

    private function format(string $message, ConsoleTone $tone): string
    {
        if (!$this->inActions || $tone === ConsoleTone::Standard) {
            return $message;
        }

        return match ($tone) {
            ConsoleTone::Notice => "\033[1;34m{$message}\033[0m",
            ConsoleTone::Standard => $message,
        };
    }
}
