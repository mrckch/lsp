<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultAssessmentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'eingangstest', 'label' => 'Eingangstest', 'sort_order' => 10],
            ['key' => 'zwischentest', 'label' => 'Zwischenerhebung', 'sort_order' => 20],
            ['key' => 'abschlusstest', 'label' => 'Abschlusstest', 'sort_order' => 30],
            ['key' => 'foerderdiagnostik', 'label' => 'Förderdiagnostik', 'sort_order' => 40],
        ];

        $now = now();
        foreach ($defaults as $row) {
            DB::table('assessment_types')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'label' => $row['label'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
