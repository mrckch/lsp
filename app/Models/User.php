<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crypto\Models\KeyWrap;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserPermissionOverride;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'username',
        'email',
        'display_name',
        'password',
        'is_active',
        'two_factor_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'last_2fa_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_memberships')
            ->withTimestamps();
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function scopeAssignments(): HasMany
    {
        return $this->hasMany(UserScopeAssignment::class);
    }

    public function keyWraps(): HasMany
    {
        return $this->hasMany(KeyWrap::class);
    }

    public function hasPermission(string $key): bool
    {
        return app(PermissionResolver::class)->can($this, $key);
    }
}
