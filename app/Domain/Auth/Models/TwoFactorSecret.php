<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFactorSecret extends Model
{
    protected $fillable = [
        'user_id',
        'secret_encrypted',
        'recovery_codes_encrypted',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
