<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class MailSettings extends Model
{
    protected $table = 'mail_settings';

    protected $fillable = [
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password_encrypted',
        'smtp_encryption', 'from_address', 'from_name', 'reply_to', 'is_active',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function singleton(): self
    {
        return self::query()->firstOrCreate(['id' => 1]);
    }

    /**
     * Klartext-Passwort. Wird beim Set automatisch via Laravel-Encryption verschlüsselt.
     */
    protected function smtpPassword(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->smtp_password_encrypted
                ? decrypt($this->smtp_password_encrypted)
                : null,
            set: fn ($value) => [
                'smtp_password_encrypted' => $value === null || $value === '' ? null : encrypt($value),
            ],
        );
    }
}
