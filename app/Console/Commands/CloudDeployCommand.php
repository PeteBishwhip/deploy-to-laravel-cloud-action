<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\DeployRunner;

class CloudDeployCommand extends Command
{
    protected $signature = 'cloud:deploy';

    protected $description = 'Deploy a Laravel Cloud environment and report progress.';

    public function handle(): int
    {
        $runner = new DeployRunner();

        return $runner->handle();
    }
}
