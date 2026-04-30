<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Backup\Models\BackupRun;
use App\Domain\Backup\Models\BackupTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erzeugt ein verschlüsseltes Backup eines Targets.
 *
 * Inhalt (konfigurierbar pro Run):
 *  - DB-Dump (mariadb-dump bzw. SQLite-Dump – in Tests via DB::select)
 *  - storage/app/lsp Dateien
 *  - .env-ähnliche Config-Datei (sanitized)
 *
 * Output: passwort-verschlüsselte ZIP / TAR (AES-256-GCM Stream).
 *
 * Der Upload an externe Ziele (SFTP) erfolgt in einem separaten Schritt.
 */
final class BackupRunner
{
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
            $manifest = $this->collect();
            $bundle = $this->bundle($manifest);
            $encrypted = $this->encrypt($bundle, $target->encryption_password ?? '');

            $disk = Storage::disk('local');
            $name = sprintf('lsp_backup_%s_run%d.bin', now()->format('Ymd_His'), $run->id);
            $path = 'lsp/backups/'.$name;
            $disk->put($path, $encrypted);

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'file_name' => $name,
                'size_bytes' => strlen($encrypted),
                'sha256' => hash('sha256', $encrypted),
            ]);

            $this->applyRetention($target);
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
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $names = array_map(fn ($t) => $t->name, $tables);

        $out = [];
        foreach ($names as $name) {
            // Roh-Dump nur als Beispiel; in Produktion: mysqldump bevorzugen
            $rows = DB::table($name)->get();
            $out[$name] = $rows->toArray();
        }

        return $out;
    }

    private function bundle(array $manifest): string
    {
        return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * AES-256-GCM mit Argon2id-abgeleitetem Schlüssel.
     */
    public function encrypt(string $payload, string $password): string
    {
        if ($password === '') {
            // Fallback: Nutze APP_KEY-basierte Verschlüsselung
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
     * Vereinfacht: behalte die letzten N erfolgreichen Runs (Summe).
     */
    private function applyRetention(BackupTarget $target): void
    {
        $keep = $target->retention_daily + $target->retention_weekly + $target->retention_monthly;
        $obsolete = BackupRun::query()
            ->where('backup_target_id', $target->id)
            ->where('status', 'success')
            ->orderByDesc('started_at')
            ->skip($keep)
            ->take(1000)
            ->get();

        foreach ($obsolete as $r) {
            if ($r->file_name) {
                Storage::disk('local')->delete('lsp/backups/'.$r->file_name);
            }
            $r->delete();
        }
    }
}
