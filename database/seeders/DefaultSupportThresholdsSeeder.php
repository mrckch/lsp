<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\SupportThreshold\Models\SupportThreshold;
use Illuminate\Database\Seeder;

class DefaultSupportThresholdsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'LQ unter 85 (auffällig)',         'metric' => 'lq_absolute', 'operator' => 'lt', 'value' => 85, 'severity' => 'auffaellig'],
            ['name' => 'LQ unter 70 (Förderbedarf)',      'metric' => 'lq_absolute', 'operator' => 'lt', 'value' => 70, 'severity' => 'foerderbedarf'],
            ['name' => 'Negativer Trend Δ < -10 (Hinweis)', 'metric' => 'lq_delta', 'operator' => 'lt', 'value' => -10, 'window_count' => 2, 'severity' => 'hinweis'],
        ];

        foreach ($defaults as $row) {
            SupportThreshold::query()->firstOrCreate(
                ['name' => $row['name']],
                [...$row, 'is_active' => true],
            );
        }
    }
}
