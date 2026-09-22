<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalysisDataset;
use App\Domain\Analytics\AnalysisFilter;
use App\Domain\Analytics\AnalysisReport;
use App\Domain\TestRun\Models\AssessmentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalysisDevelopmentTest extends TestCase
{
    use AnalysisFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAnalysis();

        // Frühjahr: Anna 80→70 (Δ −10, Grenze „< −10“ nicht erreicht), Clara 60→45 (Δ −15), Ben 100→108
        $spring = $this->makeRun('Frühjahr 5', AssessmentType::create(['key' => 'fj', 'label' => 'Frühjahr', 'sort_order' => 2]), [$this->g5a, $this->g5b]);
        foreach (['Anna' => 70, 'Clara' => 45, 'Ben' => 108] as $name => $lq) {
            $this->attempt($this->students[$name], $spring, $lq, submittedAt: now()->toDateTimeString());
        }
    }

    /** @return array<string, mixed> */
    private function development(string $groupBy = 'learning_group'): array
    {
        $filter = AnalysisFilter::fromArray(['group_by' => $groupBy]);

        return app(AnalysisReport::class)->development(
            app(AnalysisDataset::class)->rows($filter, $this->admin, perWave: true),
            $filter->groupBy,
        );
    }

    #[Test]
    public function waves_are_chronological_and_keep_one_attempt_per_student_and_wave(): void
    {
        $dev = $this->development();

        $this->assertTrue($dev['enough']);
        $this->assertSame(['Herbst 26/27', 'Frühjahr 26/27'], array_column($dev['waves'], 'label'));
        $this->assertSame([5, 3], array_column($dev['waves'], 'n'));
        $this->assertSame(['5a', '5b'], array_column($dev['series'], 'label'));
        // 5a Herbst: 80, 100 → Median 90; Frühjahr: 70, 108 → Median 89
        $this->assertSame(90.0, $dev['series'][0]['points'][0]['median']);
        $this->assertSame(89.0, $dev['series'][0]['points'][1]['median']);
    }

    #[Test]
    public function deltas_pair_students_and_flag_by_delta_threshold(): void
    {
        $dev = $this->development();

        $this->assertSame(3, $dev['delta_total']['n']);
        $this->assertSame(1, $dev['delta_total']['improved']);
        $this->assertSame(2, $dev['delta_total']['declined']);
        $this->assertSame(1, $dev['delta_total']['flagged']);   // nur Clara (Δ −15 < −10)
        $this->assertSame([-10, 'lt'], [$dev['delta_cut'], $dev['delta_cut_op']]);
        $this->assertSame(['Clara Test', 'Anna Test'], array_column($dev['declines'], 'name'));
        $this->assertSame([-15, -10], array_column($dev['declines'], 'delta'));
        $this->assertSame([true, false], array_column($dev['declines'], 'flagged'));
    }

    #[Test]
    public function a_single_wave_is_not_enough(): void
    {
        $filter = AnalysisFilter::fromArray(['assessment_type_ids' => [$this->autumnType->id]]);
        $dev = app(AnalysisReport::class)->development(app(AnalysisDataset::class)->rows($filter, $this->admin, perWave: true), 'learning_group');

        $this->assertFalse($dev['enough']);
        $this->assertCount(1, $dev['waves']);
    }

    #[Test]
    public function teacher_only_sees_development_of_own_class(): void
    {
        $filter = AnalysisFilter::fromArray([]);
        $dev = app(AnalysisReport::class)->development(app(AnalysisDataset::class)->rows($filter, $this->teacher, perWave: true), 'learning_group');

        $this->assertSame(['5a'], array_column($dev['series'], 'label'));
        $this->assertSame(['Anna Test'], array_column($dev['declines'], 'name'));
    }
}
