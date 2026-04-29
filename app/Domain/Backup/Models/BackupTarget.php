<?php

declare(strict_types=1);

namespace App\Domain\Backup\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupTarget extends Model
{
    protected $fillable = [
        'name', 'type', 'config_encrypted', 'encryption_password_encrypted',
        'retention_daily', 'retention_weekly', 'retention_monthly', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_encrypted' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
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
