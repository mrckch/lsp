<?php

declare(strict_types=1);

namespace App\Domain\Permission\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'area',
        'description',
        'is_scopeable',
        'requires_two_factor',
    ];

    protected function casts(): array
    {
        return [
            'is_scopeable' => 'boolean',
            'requires_two_factor' => 'boolean',
        ];
    }

    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'group_permissions')
            ->withTimestamps();
    }
}
