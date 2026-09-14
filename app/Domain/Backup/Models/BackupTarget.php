<?php

declare(strict_types=1);

namespace App\Domain\Backup\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `config_encrypted` (Cast `encrypted:array`, App-Key) enthält je nach `type`:
 *   - 'local': ungenutzt
 *   - 'sftp': {host, port, username, password | private_key [+ passphrase], root, host_fingerprint}
 */
class BackupTarget extends Model
{
    protected $fillable = [
        'name', 'type', 'config_encrypted', 'encryption_password_encrypted',
        'retention_daily', 'retention_weekly', 'retention_monthly', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_encrypted' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        // getAttribute statt Property: liefert den gecasteten Wert (array|null)
        $config = $this->getAttribute('config_encrypted');

        return is_array($config) ? ($config[$key] ?? $default) : $default;
    }

    /**
     * Klartext-Passwort (verschlüsselt gespeichert).
     */
    protected function encryptionPassword(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->encryption_password_encrypted
                ? decrypt($this->encryption_password_encrypted)
                : null,
            set: fn ($v) => [
                'encryption_password_encrypted' => $v === null || $v === '' ? null : encrypt($v),
            ],
        );
    }
}
