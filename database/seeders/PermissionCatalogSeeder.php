<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Permission\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Spielt den statischen Permission-Katalog in die DB.
 * Idempotent: bestehende Keys werden aktualisiert, fehlende neu angelegt,
 * nicht mehr im Katalog vorhandene werden gelöscht (außer sie sind referenziert).
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = PermissionCatalog::all();
        $now = now();

        $existingKeys = DB::table('permissions')->pluck('key')->all();
        $catalogKeys = array_column($catalog, 'key');

        DB::transaction(function () use ($catalog, $now, $existingKeys, $catalogKeys) {
            foreach ($catalog as $perm) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $perm['key']],
                    [
                        'area' => $perm['area'],
                        'description' => $perm['description'],
                        'is_scopeable' => $perm['is_scopeable'],
                        'requires_two_factor' => $perm['requires_two_factor'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            $obsolete = array_diff($existingKeys, $catalogKeys);
            if (! empty($obsolete)) {
                DB::table('permissions')->whereIn('key', $obsolete)->delete();
            }
        });
    }
}
