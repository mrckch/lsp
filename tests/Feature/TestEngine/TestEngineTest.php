<?php

declare(strict_types=1);

namespace Tests\Feature\TestEngine;

use App\Domain\Attempt\Models\AttemptLqHistory;
use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\TestEngine;
use App\Domain\Crypto\CryptoService;
use App\Domain\NormTable\LqResolver;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\User;
use Database\Seeders\DefaultAssessmentTypesSeeder;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TestEngineTest extends TestCase
{
    use RefreshDatabase;

    private TestEngine $engine;

    private User $admin;

    private SchoolYear $sy;

    private Questionnaire $questionnaire;

    private NormTable $norm;

    private TestRun $run;

    private Student $student;

    private StudentLoginCode $loginCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DefaultUserGroupsSeeder::class);
        $this->seed(DefaultAssessmentTypesSeeder::class);

        $this->admin = User::create([
            'username' => 'admin',
            'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'),
            'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'clear-pw-1234567890');
        $this->actingAs($this->admin);

        $this->sy = SchoolYear::create([
            'label' => '2026/27', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31',
        ]);
        $group = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse']);

        $this->questionnaire = Questionnaire::create([
            'name' => 'SLS Form A1',
            'parallel_form' => 'A1',
            'default_time_limit_seconds' => 180,
            'practice_time_seconds' => 30,
            'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        // 5 Fragen: 3 richtig (sätze die richtig sind), 2 falsch
        $items = [
            ['Bananen sind gelb.', 'richtig'],
            ['Schnee ist rot.', 'falsch'],
            ['In einem Wald stehen viele Bäume.', 'richtig'],
            ['Die Sonne ist viereckig.', 'falsch'],
            ['Ein Hund hat vier Beine.', 'richtig'],
        ];
        foreach ($items as $i => [$text, $correct]) {
            QuestionnaireQuestion::create([
                'questionnaire_id' => $this->questionnaire->id,
                'sort_order' => $i + 1,
                'question_text' => $text,
                'correct_answer' => $correct,
            ]);
        }

        $this->norm = NormTable::create([
            'name' => 'SLS Norm Klasse 5 A1',
            'grade_level' => '5',
            'parallel_form' => 'A1',
            'is_active' => true,
            'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        // Norm-Zeilen: raw_score → quotient (für 5 Fragen)
        foreach (range(0, 5) as $score) {
            NormTableRow::create([
                'norm_table_id' => $this->norm->id,
                'raw_score' => $score,
                'quotient_male' => 70 + $score * 6,
                'quotient_female' => 75 + $score * 6,
            ]);
        }

        $this->run = TestRun::create([
            'school_year_id' => $this->sy->id,
            'name' => 'Eingangstest 5a',
            'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv',
            'questionnaire_id' => $this->questionnaire->id,
            'norm_table_id' => $this->norm->id,
            'time_limit_seconds' => 180,
            'practice_time_seconds' => 30,
            'show_score_to_student' => true,
            'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run->learningGroups()->attach($group->id);

        $this->student = new Student;
        $this->student->external_student_id = 'X1';
        $this->student->external_id_source = 'manual';
        $this->student->student_code = Student::generateUniqueCode();
        $this->student->first_name_encrypted = 'Anna';
        $this->student->last_name_encrypted = 'Müller';
        $this->student->gender = 'w';
        $this->student->save();
        $this->student->memberships()->create([
            'learning_group_id' => $group->id,
            'school_year_id' => $this->sy->id,
        ]);

        $this->engine = app(TestEngine::class);

        // Login-Codes erzeugen
        $this->engine->issueLoginCodes($this->run);
        $this->loginCode = StudentLoginCode::query()->where('student_id', $this->student->id)->firstOrFail();
    }

    #[Test]
    public function it_issues_one_login_code_per_student(): void
    {
        $this->assertEquals(1, StudentLoginCode::count());
        $this->assertEquals(10, strlen($this->loginCode->login_code));
        $this->assertEquals('aktiv', $this->loginCode->status);
    }

    #[Test]
    public function login_by_code_returns_student_and_run(): void
    {
        $info = $this->engine->loginByCode($this->loginCode->login_code);

        $this->assertNotNull($info);
        $this->assertEquals($this->student->id, $info['student']->id);
        $this->assertEquals($this->run->id, $info['test_run']->id);
    }

    #[Test]
    public function login_returns_null_for_unknown_code(): void
    {
        $this->assertNull($this->engine->loginByCode('AAAAAAAAAA'));
    }

    #[Test]
    public function start_attempt_creates_one_and_updates_login_code_status(): void
    {
        $attempt = $this->engine->startAttempt($this->student, $this->run, $this->loginCode);

        $this->assertNotNull($attempt);
        $this->assertEquals('gestartet', $attempt->status);
        $this->loginCode->refresh();
        $this->assertEquals('in_bearbeitung', $this->loginCode->status);
    }

    #[Test]
    public function save_answer_marks_correctness_and_updateable(): void
    {
        $attempt = $this->engine->startAttempt($this->student, $this->run, $this->loginCode);
        $q = $this->questionnaire->questions->first();

        $this->assertTrue($this->engine->saveAnswer($attempt, $q->id, 'richtig'));
        $this->assertEquals(1, $attempt->answers()->count());

        // Antwort ändern
        $this->assertTrue($this->engine->saveAnswer($attempt, $q->id, 'falsch'));
        $this->assertEquals(1, $attempt->answers()->count());
        $this->assertEquals('falsch', $attempt->answers()->first()->given_answer);
    }

    #[Test]
    public function submit_calculates_score_and_lq(): void
    {
        $attempt = $this->engine->startAttempt($this->student, $this->run, $this->loginCode);
        // 4 von 5 richtig beantworten
        foreach ($this->questionnaire->questions->take(4) as $q) {
            $this->engine->saveAnswer($attempt, $q->id, $q->correct_answer);
        }
        $attempt = $this->engine->submitAttempt($attempt, 'schueler');

        $this->assertEquals('abgegeben', $attempt->status);
        $this->assertEquals(4, $attempt->score_raw);
        // norm: 75 + 4*6 = 99 (female)
        $this->assertEquals(99, $attempt->lq_at_submission);
        $this->assertEquals(99, $attempt->lq_current);

        $this->loginCode->refresh();
        $this->assertEquals('verbraucht', $this->loginCode->status);

        $this->assertEquals(1, AttemptLqHistory::count());
    }

    #[Test]
    public function submit_returns_null_lq_if_no_norm_row_matches(): void
    {
        // Versuche unbeantwortet abgeben → score=0 → existiert in norm (75)
        // Daher anderen Score testen: erst 5 Norm-Zeilen, score=6 nicht existent
        // Wir machen die Frage einfacher: leere Norm-Zeilen
        NormTableRow::query()->where('norm_table_id', $this->norm->id)->delete();

        $attempt = $this->engine->startAttempt($this->student, $this->run, $this->loginCode);
        $attempt = $this->engine->submitAttempt($attempt, 'schueler');

        $this->assertNull($attempt->lq_at_submission);
        $this->assertNull($attempt->lq_current);
    }

    #[Test]
    public function reset_attempt_deletes_answers_and_reactivates_login(): void
    {
        $attempt = $this->engine->startAttempt($this->student, $this->run, $this->loginCode);
        foreach ($this->questionnaire->questions->take(3) as $q) {
            $this->engine->saveAnswer($attempt, $q->id, $q->correct_answer);
        }
        $this->engine->submitAttempt($attempt, 'schueler');

        $this->engine->resetAttempt($attempt->refresh(), $this->admin->id, 'Doppelte Eingabe');

        $attempt->refresh();
        $this->assertEquals('zurueckgesetzt', $attempt->status);
        $this->assertEquals(0, $attempt->answers()->count());

        $this->loginCode->refresh();
        $this->assertEquals('aktiv', $this->loginCode->status);
    }

    #[Test]
    public function recalculate_lq_creates_history_entry(): void
    {
        $attempt = $this->engine->startAttempt($this->student, $this->run, $this->loginCode);
        foreach ($this->questionnaire->questions->take(3) as $q) {
            $this->engine->saveAnswer($attempt, $q->id, $q->correct_answer);
        }
        $attempt = $this->engine->submitAttempt($attempt, 'schueler');

        // Norm anpassen: für score=3 jetzt anderen Wert
        NormTableRow::query()
            ->where('norm_table_id', $this->norm->id)
            ->where('raw_score', 3)
            ->update(['quotient_female' => 200]);

        $this->engine->recalculateLq($attempt, null, 'norm_table_updated');
        $attempt->refresh();
        $this->assertEquals(200, $attempt->lq_current);
        $this->assertEquals(2, AttemptLqHistory::where('test_attempt_id', $attempt->id)->count());
    }

    #[Test]
    public function lq_resolver_picks_correct_gender_column(): void
    {
        $resolver = app(LqResolver::class);

        $this->assertEquals(75 + 2 * 6, $resolver->resolve(2, 'w', $this->norm));
        $this->assertEquals(70 + 2 * 6, $resolver->resolve(2, 'm', $this->norm));
    }
}
