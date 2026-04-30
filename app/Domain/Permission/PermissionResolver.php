<?php

declare(strict_types=1);

namespace App\Domain\Permission;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Berechnet effective_permissions(user) und prüft can()-Anfragen.
 *
 * Effective = (Σ Klassen-Permissions) ∪ Grants ∖ Revokes
 *
 * Scope-Prüfung erfolgt in einer separaten Methode mit Bezug auf
 * eine konkrete learning_group_id oder eine Liste davon.
 */
final class PermissionResolver
{
    public function __construct(
        private readonly bool $useCache = true,
        private readonly int $cacheTtlSeconds = 60,
    ) {}

    /**
     * Liefert die effektiven Permission-Keys eines Users.
     *
     * @return array<string, true> Map (Lookup ist O(1))
     */
    public function effectivePermissions(User $user): array
    {
        $key = "permissions:user:{$user->id}";

        if ($this->useCache) {
            return Cache::remember($key, $this->cacheTtlSeconds, fn () => $this->compute($user));
        }

        return $this->compute($user);
    }

    /**
     * Globale can()-Prüfung (ohne Scope).
     */
    public function can(User $user, string $permissionKey): bool
    {
        return isset($this->effectivePermissions($user)[$permissionKey]);
    }

    /**
     * Scoped can()-Prüfung gegen eine konkrete Lerngruppe.
     */
    public function canForLearningGroup(User $user, string $permissionKey, int $learningGroupId): bool
    {
        if (! $this->can($user, $permissionKey)) {
            return false;
        }

        // Prüfen, ob die Permission scope-fähig ist
        $isScopeable = (bool) DB::table('permissions')
            ->where('key', $permissionKey)
            ->value('is_scopeable');

        if (! $isScopeable) {
            return true;
        }

        $userScopes = $this->scopeLearningGroupIds($user);

        // Ungescoped → uneingeschränkt
        if ($userScopes === null) {
            return true;
        }

        return in_array($learningGroupId, $userScopes, true);
    }

    /**
     * @return array<int>|null null = ungescoped (sieht alles), Array = nur diese learning_group_ids
     */
    public function scopeLearningGroupIds(User $user): ?array
    {
        $key = "scope:user:{$user->id}";

        if ($this->useCache) {
            return Cache::remember($key, $this->cacheTtlSeconds, fn () => $this->loadScopes($user));
        }

        return $this->loadScopes($user);
    }

    public function flush(?User $user = null): void
    {
        if ($user !== null) {
            Cache::forget("permissions:user:{$user->id}");
            Cache::forget("scope:user:{$user->id}");

            return;
        }
        // Globaler Flush: kein einfaches Pattern – wird beim Re-Seed manuell gemacht.
    }

    /**
     * @return array<string, true>
     */
    private function compute(User $user): array
    {
        // Aus Klassen
        $classPermissions = DB::table('group_permissions as gp')
            ->join('user_group_memberships as m', 'm.user_group_id', '=', 'gp.user_group_id')
            ->join('permissions as p', 'p.id', '=', 'gp.permission_id')
            ->where('m.user_id', $user->id)
            ->pluck('p.key')
            ->all();

        $effective = [];
        foreach ($classPermissions as $k) {
            $effective[$k] = true;
        }

        // Overrides
        $overrides = DB::table('user_permission_overrides as o')
            ->join('permissions as p', 'p.id', '=', 'o.permission_id')
            ->where('o.user_id', $user->id)
            ->select('p.key', 'o.mode')
            ->get();

        foreach ($overrides as $o) {
            if ($o->mode === 'grant') {
                $effective[$o->key] = true;
            } else {
                unset($effective[$o->key]);
            }
        }

        return $effective;
    }

    /**
     * @return array<int>|null
     */
    private function loadScopes(User $user): ?array
    {
        $rows = DB::table('user_scope_assignments')
            ->where('user_id', $user->id)
            ->pluck('learning_group_id')
            ->all();

        if (empty($rows)) {
            return null; // ungescoped
        }

        return array_map('intval', $rows);
    }
}
