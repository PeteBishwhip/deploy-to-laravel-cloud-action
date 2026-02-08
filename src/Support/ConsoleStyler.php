<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Support;

class ConsoleStyler
{
    private bool $inActions;

    public function __construct(?bool $inActions = null)
    {
        $this->inActions = $inActions ?? (getenv('GITHUB_ACTIONS') === 'true');
    }

    public function notice(string $message): string
    {
        if (!$this->inActions) {
            return $message;
        }

        return "\033[1;34m{$message}\033[0m";
    }
}
