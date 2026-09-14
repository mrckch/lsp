<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Backup\Models\BackupRun;
use App\Domain\Backup\Models\BackupTarget;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Erzeugt ein verschlüsseltes Backup eines Targets.
 *
 * Inhalt:
 *  - alle Tabellen der aktuellen Datenbank (SQLite und MariaDB/MySQL)
 *  - Storage-Dateien aus config('lsp.backup.include_paths')
 *
 * Output: JSON-Manifest, AES-256-GCM-verschlüsselt (Argon2id-KEK), immer als
 * lokale Kopie unter lsp/backups/. Bei externen Zielen (SFTP) wird dieselbe
 * Datei zusätzlich hochgeladen; der Run gilt nur bei erfolgreichem Upload als OK.
 */
final class BackupRunner
{
    /** Markiert Binärwerte (kein valides UTF-8) im JSON-Manifest. */
    public const BINARY_MARKER = '__lsp_b64';

    public function __construct(private readonly BackupDiskFactory $disks) {}

    public function run(BackupTarget $target, string $trigger = 'manual', ?int $userId = null): BackupRun
    {
        $run = BackupRun::create([
            'backup_target_id' => $target->id,
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
            'includes_db' => true,
            'includes_files' => true,
            'includes_config' => true,
            'triggered_by_user_id' => $userId,
        ]);

        try {
            $password = (string) ($target->encryption_password ?? '');
            if ($password === '') {
                throw new \RuntimeException('Backup-Ziel hat kein Backup-Passwort – unverschlüsselte Backups werden nicht erstellt.');
            }

            $remote = $this->disks->forTarget($target);

            $encrypted = $this->encrypt($this->bundle($this->collect()), $password);

            $name = sprintf('lsp_backup_%s_run%d.bin', now()->format('Ymd_His'), $run->id);
            if (! Storage::disk('local')->put('lsp/backups/'.$name, $encrypted)) {
                throw new \RuntimeException('Backup-Datei konnte lokal nicht geschrieben werden.');
            }

            $run->update([
                'file_name' => $name,
                'size_bytes' => strlen($encrypted),
                'sha256' => hash('sha256', $encrypted),
            ]);

            if ($remote !== null) {
                $this->upload($remote, $name, $encrypted);
            }

            $run->update(['status' => 'success', 'finished_at' => now()]);

            $this->applyRetention($target, $remote);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }

        return $run->refresh();
    }

    /**
     * Prüft ein externes Ziel mit einer kleinen Testdatei (schreiben, Größe prüfen, löschen).
     *
     * @throws \RuntimeException
     */
    public function testConnection(BackupTarget $target): void
    {
        $remote = $this->disks->forTarget($target);
        if ($remote === null) {
            return;
        }

        $probe = '.lsp_connection_test_'.bin2hex(random_bytes(4));
        $this->upload($remote, $probe, 'ok');
        $remote->delete($probe);
    }

    /**
     * Erzeugt einen Standalone-Snapshot ohne BackupTarget — z. B. als Pre-Restore-
     * Notfall-Snapshot. Schreibt unverschlüsselt (NOENC) ins lokale Backup-Verzeichnis,
     * weil die DEK aus dem Backup-Target-Passwort hier nicht verfügbar wäre.
     *
     * @return array{path:string, size:int, sha256:string}
     */
    public function createStandaloneSnapshot(string $reason = 'snapshot'): array
    {
        $manifest = $this->collect();
        $manifest['standalone_snapshot'] = true;
        $manifest['reason'] = $reason;

        $bundle = $this->bundle($manifest);
        $encrypted = $this->encrypt($bundle, ''); // NOENC: lokal, kein Passwort

        $disk = Storage::disk('local');
        $name = sprintf('lsp_snapshot_%s_%s.bin',
            preg_replace('/[^a-z0-9_-]/i', '_', $reason),
            now()->format('Ymd_His'),
        );
        $path = 'lsp/backups/'.$name;
        $disk->put($path, $encrypted);

        return [
            'path' => $path,
            'size' => strlen($encrypted),
            'sha256' => hash('sha256', $encrypted),
        ];
    }

    /**
     * Tabellen der aktuellen Datenbank — treiberunabhängig über den Schema-Builder.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        $tables = Schema::getTables(Schema::getCurrentSchemaName());

        return array_values(array_unique(array_column($tables, 'name')));
    }

    /**
     * Macht Binärwerte JSON-tauglich (Rückweg: decodeValue).
     */
    public static function encodeValue(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return [self::BINARY_MARKER => base64_encode($value)];
        }

        return $value;
    }

    public static function decodeValue(mixed $value): mixed
    {
        if (is_array($value) && array_keys($value) === [self::BINARY_MARKER]) {
            return base64_decode((string) $value[self::BINARY_MARKER], true);
        }

        return $value;
    }

    /**
     * Sammelt die zu sichernden Inhalte: DB-Tabellen + Storage-Files (Whitelist).
     */
    private function collect(): array
    {
        return [
            'created_at' => now()->toIso8601String(),
            'app_version' => config('app.version', '0.5.0-dev'),
            'tables' => $this->dumpTables(),
            'files' => $this->dumpFiles(),
        ];
    }

    /**
     * Sammelt Storage-Dateien aus den in config('lsp.backup.include_paths') konfigurierten
     * Verzeichnissen. Jede Datei wird als base64-Content im Manifest abgelegt.
     *
     * @return array<string, array{content_b64:string, size:int, mtime:int}>
     */
    private function dumpFiles(): array
    {
        $disk = Storage::disk('local');
        $maxBytes = (int) config('lsp.backup.max_file_size_bytes', 50 * 1024 * 1024);
        $paths = (array) config('lsp.backup.include_paths', []);

        $files = [];
        foreach ($paths as $base) {
            $base = trim($base, '/');
            if ($base === '' || ! $disk->exists($base)) {
                continue;
            }
            foreach ($disk->allFiles($base) as $relPath) {
                $size = $disk->size($relPath);
                if ($size > $maxBytes) {
                    // Riesen-Datei → nur Pfad/Größe vermerken, aber kein Content
                    $files[$relPath] = [
                        'content_b64' => null,
                        'size' => $size,
                        'mtime' => $disk->lastModified($relPath),
                        'skipped_reason' => 'datei_zu_gross',
                    ];

                    continue;
                }
                $files[$relPath] = [
                    'content_b64' => base64_encode((string) $disk->get($relPath)),
                    'size' => $size,
                    'mtime' => $disk->lastModified($relPath),
                ];
            }
        }

        return $files;
    }

    private function dumpTables(): array
    {
        $out = [];
        foreach ($this->tableNames() as $name) {
            $out[$name] = DB::table($name)->get()
                ->map(fn ($row) => array_map(self::encodeValue(...), (array) $row))
                ->all();
        }

        return $out;
    }

    private function bundle(array $manifest): string
    {
        return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function upload(Filesystem $remote, string $name, string $contents): void
    {
        try {
            $remote->put($name, $contents);
            $size = $remote->size($name);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Upload zum externen Ziel fehlgeschlagen: '.$e->getMessage(), previous: $e);
        }

        if ($size !== strlen($contents)) {
            throw new \RuntimeException(sprintf('Upload unvollständig: %d von %d Bytes übertragen.', $size, strlen($contents)));
        }
    }

    /**
     * AES-256-GCM mit Argon2id-abgeleitetem Schlüssel.
     */
    public function encrypt(string $payload, string $password): string
    {
        if ($password === '') {
            // Nur für lokale Notfall-Snapshots (createStandaloneSnapshot)
            return 'NOENC:'.base64_encode($payload);
        }
        $salt = random_bytes(16);
        $kek = \sodium_crypto_pwhash(
            32,
            $password,
            $salt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($payload, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag);
        \sodium_memzero($kek);

        return 'ENC1:'.base64_encode($salt.$nonce.$tag.$cipher);
    }

    public function decrypt(string $encoded, string $password): string
    {
        if (str_starts_with($encoded, 'NOENC:')) {
            return base64_decode(substr($encoded, 6));
        }
        if (! str_starts_with($encoded, 'ENC1:')) {
            throw new \RuntimeException('Unbekanntes Backup-Format.');
        }
        $raw = base64_decode(substr($encoded, 5));
        $salt = substr($raw, 0, 16);
        $nonce = substr($raw, 16, 12);
        $tag = substr($raw, 28, 16);
        $cipher = substr($raw, 44);

        $kek = \sodium_crypto_pwhash(
            32, $password, $salt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag);
        \sodium_memzero($kek);
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen (falsches Passwort?).');
        }

        return $plain;
    }

    /**
     * Wendet Retention-Policy an: behalte X tägliche / Y wöchentliche / Z monatliche Runs.
     * Vereinfacht: behalte die letzten N erfolgreichen Runs (Summe) — lokal und extern.
     * Lokale Dateien fehlgeschlagener Runs (z. B. Upload-Fehler) werden nach 30 Tagen entfernt.
     */
    private function applyRetention(BackupTarget $target, ?Filesystem $remote): void
    {
        $keep = $target->retention_daily + $target->retention_weekly + $target->retention_monthly;
        $obsolete = BackupRun::query()
            ->where('backup_target_id', $target->id)
            ->where('status', 'success')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(1000)
            ->get();

        foreach ($obsolete as $r) {
            if ($r->file_name) {
                Storage::disk('local')->delete('lsp/backups/'.$r->file_name);
                try {
                    $remote?->delete($r->file_name);
                } catch (\Throwable) {
                    // Remote-Aufräumen ist best effort — der nächste Lauf versucht es nicht erneut,
                    // aber ein fehlendes Löschen gefährdet keine Daten.
                }
            }
            $r->delete();
        }

        BackupRun::query()
            ->where('backup_target_id', $target->id)
            ->where('status', 'failed')
            ->whereNotNull('file_name')
            ->where('started_at', '<', now()->subDays(30))
            ->get()
            ->each(function (BackupRun $r) {
                Storage::disk('local')->delete('lsp/backups/'.$r->file_name);
                $r->update(['file_name' => null]);
            });
    }
}
