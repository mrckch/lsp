<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Permission\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Legt die System-Benutzerklassen an und ordnet ihnen ihre Default-Permissions zu.
 *
 * Idempotent. Permissions können nachträglich vom Admin geändert werden – dieser Seeder
 * setzt sie nur beim Erstanlegen oder fehlenden Klassen neu.
 */
class DefaultUserGroupsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = PermissionCatalog::defaultsForGroups();
        $now = now();

        $sortOrder = 0;
        foreach ($defaults as $groupName => $permissionKeys) {
            $sortOrder++;
            DB::transaction(function () use ($groupName, $permissionKeys, $sortOrder, $now) {
                $existing = DB::table('user_groups')->where('name', $groupName)->first();

                if ($existing === null) {
                    $groupId = DB::table('user_groups')->insertGetId([
                        'name' => $groupName,
                        'description' => null,
                        'is_system' => true,
                        'force_two_factor' => $groupName === 'Admin',
                        'sort_order' => $sortOrder,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $this->syncPermissions($groupId, $permissionKeys, $now);
                } else {
                    // Bestehende Klasse: nur fehlende Permissions ergänzen, keine entfernen
                    $existingPermIds = DB::table('group_permissions')
                        ->where('user_group_id', $existing->id)
                        ->pluck('permission_id')
                        ->all();
                    $existingPermKeys = DB::table('permissions')
                        ->whereIn('id', $existingPermIds)
                        ->pluck('key')
                        ->all();

                    $missing = array_diff($permissionKeys, $existingPermKeys);
                    $this->addPermissions($existing->id, $missing, $now);
                }
            });
        }
    }

    private function syncPermissions(int $groupId, array $permissionKeys, $now): void
    {
        DB::table('group_permissions')->where('user_group_id', $groupId)->delete();
        $this->addPermissions($groupId, $permissionKeys, $now);
    }

    private function addPermissions(int $groupId, array $permissionKeys, $now): void
    {
        if (empty($permissionKeys)) {
            return;
        }

        $permIds = DB::table('permissions')
            ->whereIn('key', $permissionKeys)
            ->pluck('id', 'key');

        $rows = [];
        foreach ($permissionKeys as $key) {
            if (! isset($permIds[$key])) {
                continue;
            }
            $rows[] = [
                'user_group_id' => $groupId,
                'permission_id' => $permIds[$key],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('group_permissions')->insertOrIgnore($rows);
        }
    }
}
