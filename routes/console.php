<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Täglich abgelaufene generated_documents (PDF/ZIP) aufräumen.
Schedule::command('documents:cleanup')
    ->dailyAt('03:15')
    ->onOneServer()
    ->runInBackground();
