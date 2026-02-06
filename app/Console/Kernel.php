<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\DeployCommand::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // No scheduled commands.
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
