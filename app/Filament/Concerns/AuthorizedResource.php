<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Permission\PermissionResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Bindet eine Filament-Resource an den PermissionResolver.
 *
 * Resources überschreiben die Permission-Methoden mit ihren konkreten Permission-Keys.
 * Wird kein Key zurückgegeben, bleibt die Aktion offen (für Übergangsphasen).
 */
trait AuthorizedResource
{
    protected static function viewPermission(): ?string
    {
        return null;
    }

    protected static function createPermission(): ?string
    {
        return null;
    }

    protected static function editPermission(): ?string
    {
        return null;
    }

    protected static function deletePermission(): ?string
    {
        return null;
    }

    public static function canViewAny(): bool
    {
        return self::checkPermission(static::viewPermission());
    }

    public static function canCreate(): bool
    {
        return self::checkPermission(static::createPermission() ?? static::editPermission());
    }

    public static function canEdit(Model $record): bool
    {
        return self::checkPermission(static::editPermission());
    }

    public static function canDelete(Model $record): bool
    {
        return self::checkPermission(static::deletePermission());
    }

    public static function canDeleteAny(): bool
    {
        return self::checkPermission(static::deletePermission());
    }

    public static function canView(Model $record): bool
    {
        return self::checkPermission(static::viewPermission());
    }

    /**
     * Verstecke den Navigations-Eintrag automatisch, wenn der User nichts sehen darf.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    private static function checkPermission(?string $key): bool
    {
        if ($key === null) {
            return true;
        }
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return app(PermissionResolver::class)->can($user, $key);
    }
}
