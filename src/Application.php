<?php

declare(strict_types=1);

namespace LaravelCloudDeploy;

use Symfony\Component\Console\Application as SymfonyApplication;
use LaravelCloudDeploy\Command\DeployCommand;

class Application extends SymfonyApplication
{
    public static function create(string $binary): SymfonyApplication
    {
        $app = new static('Laravel Cloud Deploy', '1.0.0');
        $app->addCommand(new DeployCommand());
        $app->setDefaultCommand('deploy', true);

        return $app;
    }
}
