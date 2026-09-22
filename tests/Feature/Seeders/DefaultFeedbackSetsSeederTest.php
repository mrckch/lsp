<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Domain\FeedbackSet\Models\FeedbackSet;
use Database\Seeders\DefaultFeedbackSetsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DefaultFeedbackSetsSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_a_default_set_with_lq_bands_matching_the_thresholds(): void
    {
        $this->seed(DefaultFeedbackSetsSeeder::class);

        $set = FeedbackSet::query()->where('name', 'SLS-Standardrückmeldung (LQ)')->first();
        $this->assertNotNull($set);
        $this->assertTrue($set->is_default);
        $this->assertSame('aktiv', $set->status);
        $this->assertNull($set->created_by_user_id);

        $ranges = $set->ranges()->get();
        $this->assertCount(3, $ranges);
        $this->assertEqualsCanonicalizing(
            [[0, 69], [70, 84], [85, 200]],
            $ranges->map(fn ($r) => [$r->min_value, $r->max_value])->all(),
        );
        $this->assertTrue($ranges->every(fn ($r) => $r->match_type === 'lq' && $r->is_active));
    }

    #[Test]
    public function it_is_idempotent_and_keeps_user_edits(): void
    {
        $this->seed(DefaultFeedbackSetsSeeder::class);
        $set = FeedbackSet::query()->firstWhere('name', 'SLS-Standardrückmeldung (LQ)');
        $set->ranges()->first()->delete(); // simuliert eine Nutzer-Bearbeitung

        $this->seed(DefaultFeedbackSetsSeeder::class);

        $this->assertSame(1, FeedbackSet::query()->where('name', 'SLS-Standardrückmeldung (LQ)')->count());
        $this->assertCount(2, $set->ranges()->get()); // Bearbeitung bleibt erhalten, kein Re-Seed
    }
}
