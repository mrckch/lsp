<?php

declare(strict_types=1);

namespace App\Domain\Crypto\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecoveryKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'encryption_key_id',
        'label',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(EncryptionKey::class);
    }
}
