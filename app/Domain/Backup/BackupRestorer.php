<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Stellt ein verschlüsseltes Backup wieder her.
 *
 * Funktionsweise:
 *  1. Datei lesen + decrypten (über BackupRunner)
 *  2. Manifest validieren (app_version, Tabellen-Existenz)
 *  3. Plan ermitteln: pro Backup-Tabelle entweder restoren oder skippen
 *     - 'migrations' wird IMMER übersprungen (Schema-Drift-Schutz)
 *     - Tabellen, die nicht mehr existieren, werden geskippt
 *  4. Bei dryRun=false: Foreign-Keys deaktivieren, TRUNCATE/DELETE,
 *     INSERT in Batches, Foreign-Keys wieder aktivieren
 *  5. Audit-Eintrag schreiben
 *
 * SQLite und MariaDB/MySQL werden beide unterstützt. Für andere Treiber
 * wirft restore() vor jeder Schreiboperation eine Exception.
 */
final class BackupRestorer
{
    public const SKIP_TABLES = ['migrations'];

    public const INSERT_BATCH = 200;

    public function __construct(private readonly BackupRunner $runner) {}

    /**
     * @return array{
     *   manifest_version: ?string,
     *   tables_planned: list<string>,
     *   tables_skipped: array<string, string>,
     *   tables_missing_in_db: list<string>,
     *   tables_extra_in_db: list<string>,
     *   restored: array<string, int>,
     *   dry_run: bool,
     *   sha256: string,
     * }
     */
    public function restore(
        string $absoluteFilePath,
        string $password,
        bool $dryRun = false,
        bool $allowVersionMismatch = false,
        ?int $actorUserId = null,
    ): array {
        if (! is_file($absoluteFilePath)) {
            throw new \RuntimeException("Backup-Datei nicht gefunden: $absoluteFilePath");
        }

        $blob = (string) file_get_contents($absoluteFilePath);
        $sha256 = hash('sha256', $blob);
        $payload = $this->runner->decrypt($blob, $password);

        try {
            $manifest = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Backup-Manifest unleserlich: '.$e->getMessage(), previous: $e);
        }

        $manifestVersion = $manifest['app_version'] ?? null;
        $appVersion = (string) config('app.version', '');
        if (
            ! $allowVersionMismatch
            && $manifestVersion !== null && $appVersion !== ''
            && $manifestVersion !== $appVersion
        ) {
            throw new \RuntimeException(
                "App-Version-Abweichung: Backup={$manifestVersion}, aktuell={$appVersion}. ".
                'Mit --allow-version-mismatch übersteuern (Vorsicht: Schema kann inkompatibel sein).',
            );
        }

        $backupTables = (array) ($manifest['tables'] ?? []);
        $currentTables = $this->currentTableNames();

        $planned = [];
        $skipped = [];
        $missingInDb = [];
        foreach ($backupTables as $name => $rows) {
            if (in_array($name, self::SKIP_TABLES, true)) {
                $skipped[$name] = 'tabelle wird absichtlich übersprungen (Schema-Drift-Schutz)';

                continue;
            }
            if (! in_array($name, $currentTables, true)) {
                $missingInDb[] = $name;
                $skipped[$name] = 'tabelle existiert nicht (mehr) im aktuellen Schema';

                continue;
            }
            $planned[] = $name;
        }
        $extraInDb = array_values(array_diff($currentTables, array_keys($backupTables), self::SKIP_TABLES));

        $restored = [];
        if (! $dryRun) {
            $this->withForeignKeysDisabled(function () use ($backupTables, $planned, &$restored) {
                foreach ($planned as $name) {
                    $rows = (array) ($backupTables[$name] ?? []);
                    $this->truncate($name);
                    $count = $this->insertRows($name, $rows);
                    $restored[$name] = $count;
                }
            });

            AuditLog::create([
                'actor_type' => $actorUserId ? 'user' : 'system',
                'actor_user_id' => $actorUserId,
                'action' => 'system.backup.restored',
                'entity_type' => 'backup',
                'entity_id' => null,
                'context' => [
                    'manifest_version' => $manifestVersion,
                    'sha256' => $sha256,
                    'tables_restored' => count($restored),
                    'rows_restored_total' => array_sum($restored),
                    'tables_skipped' => array_keys($skipped),
                ],
                'includes_clearnames' => true,
            ]);
        }

        return [
            'manifest_version' => $manifestVersion,
            'tables_planned' => $planned,
            'tables_skipped' => $skipped,
            'tables_missing_in_db' => $missingInDb,
            'tables_extra_in_db' => $extraInDb,
            'restored' => $restored,
            'dry_run' => $dryRun,
            'sha256' => $sha256,
        ];
    }

    /** @return list<string> */
    private function currentTableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => array_map(
                fn ($t) => $t->name,
                DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
            ),
            'mysql', 'mariadb' => array_map(
                fn ($t) => $t->table_name ?? array_values((array) $t)[0],
                DB::select('SELECT TABLE_NAME as table_name FROM information_schema.tables WHERE table_schema = DATABASE()'),
            ),
            default => throw new \RuntimeException("DB-Treiber '$driver' wird vom Restorer nicht unterstützt."),
        };
    }

    private function withForeignKeysDisabled(callable $fn): void
    {
        $driver = DB::connection()->getDriverName();
        try {
            match ($driver) {
                'sqlite' => DB::statement('PRAGMA foreign_keys=OFF'),
                'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
                default => throw new \RuntimeException("DB-Treiber '$driver' nicht unterstützt."),
            };
            $fn();
        } finally {
            match ($driver) {
                'sqlite' => DB::statement('PRAGMA foreign_keys=ON'),
                'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
                default => null,
            };
        }
    }

    private function truncate(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("DELETE FROM \"$table\"");
            // Auto-Increment-Reset ist optional; SQLite-sequence-Tabelle existiert nur bei AUTOINCREMENT
            try {
                DB::statement("DELETE FROM sqlite_sequence WHERE name = ?", [$table]);
            } catch (\Throwable) {
                // sqlite_sequence existiert nicht → ignorieren
            }
        } else { // mysql/mariadb
            DB::statement("TRUNCATE TABLE `$table`");
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function insertRows(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $count = 0;
        // Eloquent-/DB-table-Insert mag Stringdaten — kein Cast-Schmerz wie bei mysqldump
        $columns = Schema::getColumnListing($table);
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            $clean = array_map(function ($row) use ($columns) {
                $r = (array) $row;
                // Nur bekannte Spalten übernehmen (ältere Backups → unbekannte Felder dropen)
                return array_intersect_key($r, array_flip($columns));
            }, $chunk);
            DB::table($table)->insert($clean);
            $count += count($clean);
        }

        return $count;
    }
}
