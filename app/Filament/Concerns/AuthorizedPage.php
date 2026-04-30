<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Permission\PermissionResolver;

trait AuthorizedPage
{
    protected static function requiredPermission(): ?string
    {
        return null;
    }

    public static function canAccess(): bool
    {
        $key = static::requiredPermission();
        if ($key === null) {
            return true;
        }
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return app(PermissionResolver::class)->can($user, $key);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
