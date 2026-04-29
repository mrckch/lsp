<?php

declare(strict_types=1);

namespace App\Domain\Permission\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserScopeAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'learning_group_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
