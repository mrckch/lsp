<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Backup\Models\BackupTarget;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Liefert das externe Dateisystem eines Backup-Ziels (null = nur lokale Kopie).
 *
 * Eigene Klasse, damit Tests das Remote-Ziel über den Container durch eine
 * Fake-Disk ersetzen können.
 */
class BackupDiskFactory
{
    public function forTarget(BackupTarget $target): ?Filesystem
    {
        return match ($target->type) {
            'local' => null,
            'sftp' => $this->sftp($target),
            default => throw new \RuntimeException("Backup-Zieltyp '{$target->type}' wird nicht unterstützt."),
        };
    }

    private function sftp(BackupTarget $target): Filesystem
    {
        $host = trim((string) $target->config('host'));
        $username = trim((string) $target->config('username'));
        if ($host === '' || $username === '') {
            throw new \RuntimeException('SFTP-Ziel unvollständig: Host und Benutzer sind Pflicht.');
        }

        $config = [
            'driver' => 'sftp',
            'host' => $host,
            'port' => (int) ($target->config('port') ?: 22),
            'username' => $username,
            'root' => (string) ($target->config('root') ?: '/'),
            'timeout' => 30,
            'throw' => true,
        ];

        if (filled($target->config('private_key'))) {
            $config['privateKey'] = (string) $target->config('private_key');
            if (filled($target->config('passphrase'))) {
                $config['passphrase'] = (string) $target->config('passphrase');
            }
        } else {
            $config['password'] = (string) $target->config('password');
        }

        if (filled($target->config('host_fingerprint'))) {
            $config['hostFingerprint'] = trim((string) $target->config('host_fingerprint'));
        }

        return Storage::build($config);
    }
}
