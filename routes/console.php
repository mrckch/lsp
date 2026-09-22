<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Minütlich: Test-Versuche mit abgelaufener Zeit werten, die der Browser nie
// abgegeben hat (Tab geschlossen o. ä.) – sonst fehlt ihr LQ.
Schedule::command('attempts:finalize-expired')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

// Täglich Backup aller aktiven Backup-Ziele (lokale Kopie + ggf. SFTP-Upload).
Schedule::command('backup:run')
    ->dailyAt((string) config('lsp.backup.schedule_time', '02:30'))
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground();

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
