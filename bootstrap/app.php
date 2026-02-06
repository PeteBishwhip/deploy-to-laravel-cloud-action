<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use App\Console\Kernel;
use App\Exceptions\Handler;

$app = new Application(
    dirname(__DIR__)
);

$app->singleton(
    ConsoleKernel::class,
    Kernel::class
);

$app->singleton(
    ExceptionHandler::class,
    Handler::class
);

return $app;
