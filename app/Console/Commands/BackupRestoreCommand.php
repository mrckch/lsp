<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupRestorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * CLI-Restore eines Backups (zerstörerisch!).
 *
 * Bewusst nur als CLI verfügbar, um versehentlichen Restore aus dem UI zu verhindern.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
                            {file : Pfad oder Dateiname (relativ zum local-Disk)}
                            {--password= : Backup-Passwort (sonst Prompt)}
                            {--dry-run : Nur prüfen + Plan zeigen, keine DB-Änderung}
                            {--force : Confirmation-Prompt überspringen}
                            {--allow-version-mismatch : Restore auch bei abweichender app_version}
                            {--snapshot-before : Vor dem TRUNCATE einen lokalen Notfall-Snapshot anlegen}';

    protected $description = 'Stellt ein Backup wieder her (zerstörerisch!). Default: Dry-Run-Plan und Bestätigungsabfrage.';

    public function handle(BackupRestorer $restorer): int
    {
        $file = (string) $this->argument('file');
        if (! str_starts_with($file, 'lsp/backups/') && ! is_file($file)) {
            $file = 'lsp/backups/'.$file;
        }

        $disk = Storage::disk('local');
        $absolutePath = is_file($file) ? $file : $disk->path($file);
        if (! is_file($absolutePath)) {
            $this->error("Datei nicht gefunden: $absolutePath");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? $this->secret('Backup-Passwort (leer für unverschlüsselte Backups)'));
        $dryRun = (bool) $this->option('dry-run');
        $allowVersionMismatch = (bool) $this->option('allow-version-mismatch');
        $force = (bool) $this->option('force');
        $snapshotBefore = (bool) $this->option('snapshot-before');

        // Erst immer Plan ermitteln (Dry-Run-Logik im Restorer)
        try {
            $plan = $restorer->restore(
                absoluteFilePath: $absolutePath,
                password: $password,
                dryRun: true,
                allowVersionMismatch: $allowVersionMismatch,
            );
        } catch (\Throwable $e) {
            $this->error('Restore-Plan fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup-Plan:');
        $this->line('  app-version (Backup): '.($plan['manifest_version'] ?? '?'));
        $this->line('  app-version (aktuell): '.config('app.version', '?'));
        $this->line('  sha256: '.$plan['sha256']);
        $this->line('  tabellen zu restore: '.count($plan['tables_planned']));
        $this->line('  storage-files im Backup: '.($plan['files_total'] ?? 0));
        if ($plan['tables_planned']) {
            $this->line('    '.implode(', ', $plan['tables_planned']));
        }
        if ($plan['tables_skipped']) {
            $this->warn('  übersprungen: '.count($plan['tables_skipped']));
            foreach ($plan['tables_skipped'] as $name => $reason) {
                $this->line("    $name — $reason");
            }
        }
        if ($plan['tables_extra_in_db']) {
            $this->warn('  in DB, aber nicht im Backup (bleibt unangetastet): '.implode(', ', $plan['tables_extra_in_db']));
        }

        if ($dryRun) {
            $this->info('Dry-Run – DB wurde NICHT verändert.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->warn('⚠ Restore TRUNCATEt alle aufgeführten Tabellen und ersetzt sie durch Backup-Daten.');
            if (! $this->confirm('Wirklich fortfahren?', false)) {
                $this->warn('Abgebrochen.');

                return self::FAILURE;
            }
        }

        try {
            $result = $restorer->restore(
                absoluteFilePath: $absolutePath,
                password: $password,
                dryRun: false,
                allowVersionMismatch: $allowVersionMismatch,
                snapshotBefore: $snapshotBefore,
            );
        } catch (\Throwable $e) {
            $this->error('Restore fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['pre_snapshot_path'] !== null) {
            $this->info('Pre-Restore-Snapshot: '.$result['pre_snapshot_path']);
        }
        $totalRows = array_sum($result['restored']);
        $this->info(sprintf(
            'Restore abgeschlossen: %d Tabelle(n), %d Zeile(n), %d Datei(en) (%d übersprungen).',
            count($result['restored']),
            $totalRows,
            $result['files_restored'] ?? 0,
            $result['files_skipped'] ?? 0,
        ));
        foreach ($result['restored'] as $table => $count) {
            $this->line("  $table: $count");
        }

        return self::SUCCESS;
    }
}
