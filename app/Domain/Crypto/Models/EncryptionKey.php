<?php

declare(strict_types=1);

namespace App\Domain\Crypto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EncryptionKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key_version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'key_version' => 'integer',
            'created_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function wraps(): HasMany
    {
        return $this->hasMany(KeyWrap::class);
    }

    public function recoveryKeys(): HasMany
    {
        return $this->hasMany(RecoveryKey::class);
    }
}
