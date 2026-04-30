<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\SupportThreshold\Models\SupportThreshold;
use App\Domain\SupportThreshold\ThresholdEvaluator;
use App\Domain\TestRun\Models\TestRun;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyticsAndThresholdsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolYear $sy;

    private TestRun $run1;

    private TestRun $run2;

    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $this->sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse']);

        $q = Questionnaire::create(['name' => 'q', 'parallel_form' => 'A1', 'status' => 'aktiv', 'created_by_user_id' => $this->admin->id]);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'X', 'correct_answer' => 'richtig']);

        $this->run1 = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => 'R1', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run1->learningGroups()->attach($g->id);

        $this->run2 = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => 'R2', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run2->learningGroups()->attach($g->id);

        // 3 Schüler mit verschiedenen LQs
        foreach ([['Anna', 'A', 95, 110], ['Bob', 'B', 75, 60], ['Carl', 'C', 85, 88]] as [$f, $l, $lq1, $lq2]) {
            $s = new Student;
            $s->external_student_id = uniqid();
            $s->external_id_source = 'manual';
            $s->student_code = Student::generateUniqueCode();
            $s->first_name_encrypted = $f;
            $s->last_name_encrypted = $l;
            $s->gender = 'w';
            $s->save();
            $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $this->sy->id]);
            $s->enrollments()->create(['school_year_id' => $this->sy->id, 'enrolled_at' => now()->toDateString()]);
            $this->students[] = $s;

            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $this->run1->id,
                'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                'started_at' => now()->subDays(60), 'submitted_at' => now()->subDays(60),
                'time_limit_seconds' => 180, 'score_raw' => 5,
                'lq_at_submission' => $lq1, 'lq_current' => $lq1,
            ]);
            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $this->run2->id,
                'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                'started_at' => now()->subDays(10), 'submitted_at' => now()->subDays(10),
                'time_limit_seconds' => 180, 'score_raw' => 5,
                'lq_at_submission' => $lq2, 'lq_current' => $lq2,
            ]);
        }
    }

    #[Test]
    public function student_history_returns_chronological_attempts(): void
    {
        $history = app(AnalyticsService::class)->studentHistory($this->students[0]);
        $this->assertCount(2, $history);
        $this->assertEquals(95, $history->first()['lq_current']);
        $this->assertEquals(110, $history->last()['lq_current']);
    }

    #[Test]
    public function cohort_returns_avg_min_max_median(): void
    {
        $stats = app(AnalyticsService::class)->cohort([
            'school_year_id' => $this->sy->id,
        ]);
        $this->assertEquals(6, $stats['attempts']);
    }

    #[Test]
    public function trend_returns_delta_per_student_sorted(): void
    {
        $trend = app(AnalyticsService::class)->trend($this->run1->id, $this->run2->id);
        $this->assertCount(3, $trend);
        $this->assertEquals(-15, $trend->first()['delta']); // Bob: 60-75
        $this->assertEquals(15, $trend->last()['delta']);   // Anna: 110-95
    }

    #[Test]
    public function threshold_evaluator_finds_support_candidates(): void
    {
        SupportThreshold::create([
            'name' => 'LQ < 70', 'metric' => 'lq_absolute',
            'operator' => 'lt', 'value' => 70, 'severity' => 'foerderbedarf', 'is_active' => true,
        ]);
        SupportThreshold::create([
            'name' => 'LQ < 85', 'metric' => 'lq_absolute',
            'operator' => 'lt', 'value' => 85, 'severity' => 'auffaellig', 'is_active' => true,
        ]);

        $hits = app(ThresholdEvaluator::class)->evaluateAll($this->sy->id);

        // Bob hat aktuellen LQ 60 → foerderbedarf (höhere severity gewinnt)
        $this->assertCount(1, $hits);
        $this->assertEquals('foerderbedarf', $hits[0]['threshold']->severity);
        $this->assertEquals('B', $hits[0]['student']->last_name_encrypted);
    }
}
