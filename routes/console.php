<?php

declare(strict_types=1);

use App\Support\Schedule\IconicSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

IconicSchedule::register(app(Schedule::class));
