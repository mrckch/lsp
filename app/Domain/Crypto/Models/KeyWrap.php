<?php

declare(strict_types=1);

namespace App\Domain\Crypto\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeyWrap extends Model
{
    protected $fillable = [
        'encryption_key_id',
        'wrap_type',
        'user_id',
        'wrapped_dek',
        'wrap_nonce',
        'wrap_tag',
        'kdf_salt',
        'kdf_params',
        'verification_hash',
        'rotation_required_at',
    ];

    protected function casts(): array
    {
        return [
            'kdf_params' => 'array',
            'rotation_required_at' => 'datetime',
        ];
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(EncryptionKey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
