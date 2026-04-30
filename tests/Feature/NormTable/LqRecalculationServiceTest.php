<?php

declare(strict_types=1);

namespace Tests\Feature\NormTable;

use App\Domain\Attempt\Models\AttemptLqHistory;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\NormTable\LqRecalculationService;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LqRecalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private NormTable $norm;
    private TestRun $run;
    private Questionnaire $q;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['school_name' => 'BSP']);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $this->q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->norm = NormTable::create([
            'name' => 'N', 'grade_level' => '5', 'parallel_form' => 'A1',
            'is_active' => true, 'status' => 'aktiv', 'created_by_user_id' => $this->admin->id,
        ]);
        // initial: score 50 → 100
        NormTableRow::create([
            'norm_table_id' => $this->norm->id, 'raw_score' => 50,
            'quotient_male' => 100, 'quotient_female' => 100,
        ]);

        $this->run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $this->q->id, 'norm_table_id' => $this->norm->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run->learningGroups()->attach($g->id);

        // Drei Versuche mit score=50 und initialem LQ=100
        for ($i = 0; $i < 3; $i++) {
            $s = new Student;
            $s->external_student_id = "X$i";
            $s->external_id_source = 'manual';
            $s->student_code = Student::generateUniqueCode();
            $s->first_name_encrypted = "S$i";
            $s->last_name_encrypted = "S$i";
            $s->gender = 'w';
            $s->save();
            $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $this->run->id,
                'questionnaire_id' => $this->q->id, 'norm_table_id' => $this->norm->id,
                'status' => 'abgegeben', 'started_at' => now(), 'submitted_at' => now(),
                'time_limit_seconds' => 180, 'score_raw' => 50,
                'lq_at_submission' => 100, 'lq_current' => 100,
            ]);
        }
    }

    #[Test]
    public function recalculate_for_norm_table_updates_all_attempts(): void
    {
        // Norm-Zeile ändern: score 50 → 130 (statt 100)
        NormTableRow::query()
            ->where('norm_table_id', $this->norm->id)
            ->where('raw_score', 50)
            ->update(['quotient_male' => 130, 'quotient_female' => 130]);

        $count = app(LqRecalculationService::class)
            ->recalculateForNormTable($this->norm, $this->admin, 'test');

        $this->assertEquals(3, $count);
        // Alle drei Versuche haben lq_current=130, aber lq_at_submission=100 (immutable)
        foreach (TestAttempt::all() as $att) {
            $this->assertEquals(130, $att->lq_current);
            $this->assertEquals(100, $att->lq_at_submission);
        }
        // Pro Versuch ein History-Eintrag mit reason='test'
        $this->assertEquals(3, AttemptLqHistory::query()->where('reason', 'test')->count());
    }

    #[Test]
    public function recalculate_for_run_updates_only_runs_attempts(): void
    {
        // Zweiter Run mit anderen Versuchen
        $otherRun = TestRun::create([
            'school_year_id' => $this->run->school_year_id, 'name' => 'Other',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $this->q->id, 'norm_table_id' => $this->norm->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        TestAttempt::create([
            'student_id' => Student::first()->id, 'test_run_id' => $otherRun->id,
            'questionnaire_id' => $this->q->id, 'norm_table_id' => $this->norm->id,
            'status' => 'abgegeben', 'started_at' => now(), 'submitted_at' => now(),
            'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => 100, 'lq_current' => 100,
        ]);

        NormTableRow::query()
            ->where('norm_table_id', $this->norm->id)
            ->update(['quotient_male' => 90, 'quotient_female' => 90]);

        $count = app(LqRecalculationService::class)->recalculateForRun($this->run->id);

        $this->assertEquals(3, $count); // nur die 3 vom ursprünglichen Run
        $this->assertEquals(90, TestAttempt::where('test_run_id', $this->run->id)->first()->lq_current);
        $this->assertEquals(100, TestAttempt::where('test_run_id', $otherRun->id)->first()->lq_current);
    }
}
