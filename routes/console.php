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

// Täglich Audit-Einträge älter als config('lsp.audit.archive_after_days') soft-archivieren.
Schedule::command('audit:archive')
    ->dailyAt('03:30')
    ->onOneServer()
    ->runInBackground();

// Wöchentlich (Sonntags) archivierte Einträge älter als config('lsp.audit.purge_after_days') hard-deleten.
Schedule::command('audit:purge')
    ->weeklyOn(0, '03:45')
    ->onOneServer()
    ->runInBackground();
