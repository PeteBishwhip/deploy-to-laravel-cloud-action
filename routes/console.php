<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\CloudDeployCommand;

Artisan::command('inspire', function () {
    $this->comment('Keep shipping.');
})->purpose('Display an inspiring quote');

Artisan::starting(function ($artisan) {
    $artisan->resolveCommands([
        CloudDeployCommand::class,
    ]);
});
