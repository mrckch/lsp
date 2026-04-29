<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * CLI-Restore eines Backups.
 *
 * Bewusst nur als CLI verfügbar, um versehentlichen Restore aus dem UI zu verhindern.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore {file : Pfad oder Dateiname (relativ zum local-Disk)} {--password= : Backup-Passwort}';
    protected $description = 'Stellt ein Backup wieder her (zerstörerisch!). Erwartet manuelle Bestätigung.';

    public function handle(BackupRunner $runner): int
    {
        $file = $this->argument('file');
        if (! str_starts_with($file, 'lsp/backups/')) {
            $file = 'lsp/backups/'.$file;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($file)) {
            $this->error("Datei nicht gefunden: $file");

            return self::FAILURE;
        }

        if (! $this->confirm("⚠ Restore aus '$file' überschreibt vorhandene Daten. Fortfahren?", false)) {
            $this->warn('Abgebrochen.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->secret('Backup-Passwort');

        try {
            $payload = $runner->decrypt($disk->get($file), (string) $password);
        } catch (\Throwable $e) {
            $this->error('Decrypt fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $manifest = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->error('Backup-Inhalt ungültig: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup-Manifest:');
        $this->line('  erstellt am: '.($manifest['created_at'] ?? '?'));
        $this->line('  app-version: '.($manifest['app_version'] ?? '?'));
        $this->line('  tabellen:    '.count($manifest['tables'] ?? []));

        $this->warn('Hinweis: Echte Restore-Logik (DB-Truncate + Insert) ist als TODO markiert –');
        $this->warn('für die spätere Produktivnutzung implementiert. Diese CLI verifiziert aktuell');
        $this->warn('das Backup-Format und gibt das Manifest aus.');

        return self::SUCCESS;
    }
}
