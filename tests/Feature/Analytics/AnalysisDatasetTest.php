<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalysisDataset;
use App\Domain\Analytics\AnalysisFilter;
use App\Domain\Analytics\AnalysisReport;
use App\Domain\Analytics\DistributionStats;
use App\Domain\TestRun\Models\AssessmentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalysisDatasetTest extends TestCase
{
    use AnalysisFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAnalysis();
    }

    /** @param  array<string, mixed>  $filter */
    private function rows(User $user, array $filter = []): Collection
    {
        return app(AnalysisDataset::class)->rows(AnalysisFilter::fromArray($filter), $user);
    }

    #[Test]
    public function principal_sees_whole_school_teacher_only_own_classes(): void
    {
        $this->assertSame(5, $this->rows($this->principal)->count());
        $this->assertEqualsCanonicalizing(['Anna Test', 'Ben Test'], $this->rows($this->teacher)->pluck('name')->all());
    }

    #[Test]
    public function teacher_without_group_assignment_sees_nothing(): void
    {
        $other = $this->user('ohne', 'Lehrkraft');

        $this->assertSame([], app(AnalysisDataset::class)->allowedGroupIds($other));
        $this->assertTrue($this->rows($other)->isEmpty());
    }

    #[Test]
    public function class_is_preferred_over_course_unless_course_is_filtered(): void
    {
        $emil = $this->rows($this->admin)->firstWhere('name', 'Emil Test');
        $this->assertSame('5b', $emil['learning_group_name']);

        $inCourse = $this->rows($this->admin, ['learning_group_ids' => [$this->course->id]]);
        $this->assertEqualsCanonicalizing(['Anna Test', 'Emil Test'], $inCourse->pluck('name')->all());
        $this->assertSame(['K1'], $inCourse->pluck('learning_group_name')->unique()->values()->all());
    }

    #[Test]
    public function latest_attempt_per_student_and_reset_attempts_are_ignored(): void
    {
        $this->attempt($this->students['Anna'], $this->autumn, 90, submittedAt: now()->subMinutes(5)->toDateTimeString());
        $this->attempt($this->students['Ben'], $this->autumn, 50, status: 'zurueckgesetzt', submittedAt: now()->toDateTimeString());

        $rows = $this->rows($this->admin);
        $this->assertSame(5, $rows->count());
        $this->assertSame(90, $rows->firstWhere('name', 'Anna Test')['lq']);
        $this->assertSame(100, $rows->firstWhere('name', 'Ben Test')['lq']);

        $this->assertSame(6, $this->rows($this->admin, ['basis' => 'all_attempts'])->count());
    }

    #[Test]
    public function grouping_by_assessment_keeps_one_attempt_per_student_and_survey(): void
    {
        $spring = $this->makeRun('Frühjahr 5', AssessmentType::create(['key' => 'fj', 'label' => 'Frühjahr', 'sort_order' => 2]), [$this->g5a, $this->g5b]);
        $this->attempt($this->students['Anna'], $spring, 92, submittedAt: now()->toDateTimeString());

        $this->assertSame(5, $this->rows($this->admin)->count());
        $this->assertSame(6, $this->rows($this->admin, ['group_by' => 'assessment_type'])->count());
    }

    #[Test]
    public function filters_by_gender_grade_and_repeater(): void
    {
        $this->assertSame(3, $this->rows($this->admin, ['genders' => ['w']])->count());
        $this->assertSame(0, $this->rows($this->admin, ['genders' => ['other']])->count());
        $this->assertSame(5, $this->rows($this->admin, ['grade_levels' => ['5']])->count());
        $this->assertSame(0, $this->rows($this->admin, ['grade_levels' => ['6']])->count());

        $this->students['Clara']->enrollments()->create(['school_year_id' => $this->sy->id, 'grade_level' => '5', 'is_repeater' => true]);
        $this->assertSame(['Clara Test'], $this->rows($this->admin, ['repeater' => '1'])->pluck('name')->all());
        $this->assertSame(4, $this->rows($this->admin, ['repeater' => '0'])->count());
    }

    #[Test]
    public function report_groups_by_class_and_gender_in_order(): void
    {
        $dist = app(AnalysisReport::class)->groupedDistribution($this->rows($this->admin), 'learning_group', 'gender');

        $this->assertSame(
            ['5a · Mädchen', '5a · Jungen', '5b · Mädchen', '5b · Jungen'],
            array_map(fn ($g) => $g['label'].' · '.$g['sublabel'], $dist['groups']),
        );
        $this->assertSame([1, 1, 2, 1], array_column($dist['groups'], 'n'));
        $this->assertTrue($dist['groups'][2]['too_small']);
        $this->assertSame(5, $dist['total']['n']);
        $this->assertSame(95.0, $dist['total']['summary']['median']);
        $this->assertSame(['foerderbedarf' => 1, 'auffaellig' => 1, 'none' => 3], $dist['total']['band_counts']);
    }

    #[Test]
    public function summary_has_mean_sd_and_outliers(): void
    {
        $s = DistributionStats::summary([100, 70, 80, 85, 90, 95, 160]);

        $this->assertSame(7, $s['n']);
        $this->assertSame(90.0, $s['median']);
        $this->assertEqualsWithDelta(97.142857, $s['mean'], 0.0001);
        $this->assertEqualsWithDelta(29.4190, $s['sd'], 0.001);
        $this->assertSame([160], $s['outliers']);
        $this->assertNull(DistributionStats::summary([]));
    }

    #[Test]
    public function filter_round_trips_and_describes_itself(): void
    {
        $f = AnalysisFilter::fromArray([
            'school_year_id' => (string) $this->sy->id, 'learning_group_ids' => [(string) $this->g5a->id],
            'genders' => ['w', 'x'], 'repeater' => '0', 'group_by' => 'none', 'secondary_group_by' => 'gender',
        ]);

        $this->assertSame(['w'], $f->genders);
        $this->assertSame('gender', $f->groupBy);          // „keine“ + Aufteilung → Aufteilung wird Hauptgruppe
        $this->assertNull($f->secondaryGroupBy);
        $this->assertEquals($f, AnalysisFilter::fromArray($f->toArray()));
        $this->assertSame(
            'Schuljahr 26/27 · Lerngruppen: 5a · Mädchen · ohne Wiederholer · gruppiert nach Geschlecht · nur letzter Versuch je Schüler',
            $f->describe(),
        );
    }
}
