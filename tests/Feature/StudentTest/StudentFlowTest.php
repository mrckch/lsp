<?php

declare(strict_types=1);

namespace Tests\Feature\StudentTest;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use App\Domain\Crypto\CryptoService;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultAssessmentTypesSeeder;
use Database\Seeders\DefaultNoticeTextSeeder;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentFlowTest extends TestCase
{
    use RefreshDatabase;

    private StudentLoginCode $loginCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class, DefaultAssessmentTypesSeeder::class]);

        AppSetting::singleton()->update(['is_initialized' => true, 'initialized_at' => now()]);

        $admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($admin, 'clear-pw-1234567890');
        $this->actingAs($admin);

        $sy = SchoolYear::create(['label' => '2026/27', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $group = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $q = Questionnaire::create([
            'name' => 'A1', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'default_time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'created_by_user_id' => $admin->id,
        ]);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Test 1', 'correct_answer' => 'richtig']);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 2, 'question_text' => 'Test 2', 'correct_answer' => 'falsch']);

        $norm = NormTable::create([
            'name' => 'N', 'grade_level' => '5', 'parallel_form' => 'A1',
            'is_active' => true, 'status' => 'aktiv', 'created_by_user_id' => $admin->id,
        ]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 0, 'quotient_male' => 60, 'quotient_female' => 65]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 1, 'quotient_male' => 80, 'quotient_female' => 85]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 2, 'quotient_male' => 100, 'quotient_female' => 105]);

        $run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'Run', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id, 'norm_table_id' => $norm->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $admin->id,
        ]);
        $run->learningGroups()->attach($group->id);

        $student = new Student;
        $student->external_student_id = 'X';
        $student->external_id_source = 'manual';
        $student->student_code = Student::generateUniqueCode();
        $student->first_name_encrypted = 'A';
        $student->last_name_encrypted = 'B';
        $student->gender = 'w';
        $student->save();
        $student->memberships()->create(['learning_group_id' => $group->id, 'school_year_id' => $sy->id]);

        // Klarnamen sperren – Schüler-Test braucht keine Klarnamen
        app(CryptoService::class)->lock();
        auth()->logout();

        app(TestEngine::class)->issueLoginCodes($run);
        $this->loginCode = StudentLoginCode::query()->where('student_id', $student->id)->firstOrFail();
    }

    #[Test]
    public function unknown_code_redirects_with_error(): void
    {
        $this->post('/t/login', ['login_code' => 'AAAAAAAAAA'])
            ->assertRedirect(route('student-test.start'));
        $this->followingRedirects()
            ->post('/t/login', ['login_code' => 'AAAAAAAAAA'])
            ->assertSee('Code unbekannt');
    }

    #[Test]
    public function full_test_flow_works(): void
    {
        // 1. Login
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code])
            ->assertRedirect(route('student-test.instructions'));

        // 2. Hinweise sehen
        $this->get('/t/hinweise')->assertOk()->assertSee('Hinweise');

        // 3. Vor „Test starten“ keine Aufgaben, Timer läuft noch nicht
        $this->get('/t/aufgaben')->assertRedirect(route('student-test.instructions'));
        $this->assertNull(TestAttempt::query()->latest('id')->first()->main_started_at);

        // 4. Test starten → Timer läuft, Aufgaben sichtbar
        $this->post('/t/starten')->assertRedirect(route('student-test.questions'));
        $this->assertNotNull(TestAttempt::query()->latest('id')->first()->main_started_at);
        $this->get('/t/aufgaben')->assertOk()->assertSee('Test 1');

        // 5. Antwort abgeben (eine richtig, eine falsch)
        $attempt = TestAttempt::query()->latest('id')->first();
        $questions = $attempt->questionnaire->questions;
        $this->post('/t/antwort', ['question_id' => $questions[0]->id, 'answer' => 'richtig'])->assertOk();
        $this->post('/t/antwort', ['question_id' => $questions[1]->id, 'answer' => 'richtig'])->assertOk(); // falsch

        // 6. Submit
        $this->post('/t/abgeben')->assertRedirect(route('student-test.result'));

        // 7. Result – LQ wird angezeigt (1 Punkt → female 85)
        $this->get('/t/ergebnis')->assertOk()->assertSee('85');

        $attempt->refresh();
        $this->assertEquals('abgegeben', $attempt->status);
        $this->assertEquals(1, $attempt->score_raw);
    }

    #[Test]
    public function root_shows_student_code_login_with_staff_link(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Willkommen zum Lese-Test')
            ->assertSee('/admin/login', false);
    }

    #[Test]
    public function practice_is_skipped_without_practice_questions(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);

        $this->get('/t/hinweise')->assertOk()
            ->assertDontSee('Übung starten')
            ->assertSee('Test starten');
        $this->get('/t/uebung')->assertRedirect(route('student-test.instructions'));
    }

    #[Test]
    public function practice_shows_questions_and_does_not_start_main_timer(): void
    {
        $attemptQ = TestAttempt::query();
        $questionnaire = Questionnaire::query()->firstOrFail();
        $questionnaire->practiceQuestions()->create([
            'sort_order' => 1, 'question_text' => 'Übungssatz Eins', 'correct_answer' => 'richtig',
        ]);

        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);

        $this->get('/t/hinweise')->assertOk()->assertSee('Übung starten');
        $this->get('/t/uebung')->assertOk()
            ->assertSee('Übungssatz Eins')
            ->assertSee('Test starten');

        $attempt = $attemptQ->latest('id')->firstOrFail();
        $this->assertNull($attempt->main_started_at);
        $this->assertSame(0, $attempt->answers()->count());

        // Antworten vor „Test starten“ werden abgelehnt
        $q = $attempt->questionnaire->questions->first();
        $this->postJson('/t/antwort', ['question_id' => $q->id, 'answer' => 'richtig'])->assertStatus(422);

        $this->post('/t/starten')->assertRedirect(route('student-test.questions'));
        $this->get('/t/uebung')->assertRedirect(route('student-test.questions'));
    }

    #[Test]
    public function answers_after_time_limit_are_rejected_and_page_auto_submits(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);
        $this->post('/t/starten');

        $attempt = TestAttempt::query()->latest('id')->firstOrFail();
        $attempt->update(['main_started_at' => now()->subSeconds(180 + TestEngine::ANSWER_GRACE_SECONDS + 1)]);
        $q = $attempt->questionnaire->questions->first();

        $this->postJson('/t/antwort', ['question_id' => $q->id, 'answer' => 'richtig'])
            ->assertOk()->assertJson(['ok' => false, 'ended' => true]);

        $this->get('/t/aufgaben')->assertRedirect(route('student-test.result'));
        $this->assertSame('zeit_abgelaufen', $attempt->refresh()->status);
    }

    #[Test]
    public function relogin_after_closing_the_tab_resumes_the_same_attempt_with_answers(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);
        $this->post('/t/starten');
        $attempt = TestAttempt::query()->latest('id')->firstOrFail();
        $q = $attempt->questionnaire->questions->first();
        $this->postJson('/t/antwort', ['question_id' => $q->id, 'answer' => 'falsch'])->assertOk();

        // Neues Gerät / Cookie weg: erneut mit dem QR-Code anmelden
        $this->flushSession();
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code])
            ->assertRedirect(route('student-test.instructions'));
        $this->get('/t/hinweise')->assertRedirect(route('student-test.questions'));

        $this->assertSame(1, TestAttempt::query()->count());
        $this->get('/t/aufgaben')->assertOk()->assertViewHas('answers', [$q->id => 'falsch']);
    }

    #[Test]
    public function same_qr_code_with_open_session_returns_to_the_test(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);

        $this->get('/t?code='.$this->loginCode->login_code)->assertRedirect(route('student-test.questions'));
        $this->get('/t')->assertRedirect(route('student-test.questions'));
    }

    #[Test]
    public function other_qr_code_on_shared_device_replaces_the_old_session(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);

        $this->get('/t?code=BBBBBBBBBB')->assertOk()->assertSee('BBBBBBBBBB')
            ->assertSessionMissing('student_attempt_id');
    }

    #[Test]
    public function relogin_to_same_attempt_keeps_practice_timer(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);
        $this->withSession(['student_practice_started_at' => 12345]);

        $this->post('/t/login', ['login_code' => $this->loginCode->login_code])
            ->assertSessionHas('student_practice_started_at', 12345);
    }

    #[Test]
    public function submitted_code_shows_clear_message(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);
        $this->post('/t/starten');
        $this->post('/t/abgeben');
        $this->get('/t/ergebnis');

        $this->followingRedirects()
            ->post('/t/login', ['login_code' => $this->loginCode->login_code])
            ->assertSee('Dein Test wurde bereits abgegeben');
    }

    #[Test]
    public function instructions_show_demo_and_default_notice_text(): void
    {
        $this->seed(DefaultNoticeTextSeeder::class);
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);

        $this->get('/t/hinweise')->assertOk()
            ->assertSee('So funktioniert der Test')
            ->assertSee('Du musst nicht alle Sätze schaffen')
            ->assertSee('Gleich siehst du nacheinander einzelne Sätze');
    }

    #[Test]
    public function reset_attempt_ends_student_session_and_allows_relogin(): void
    {
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code]);
        $this->post('/t/starten');
        $attempt = TestAttempt::query()->latest('id')->firstOrFail();

        app(TestEngine::class)->resetAttempt($attempt, 1, 'Tablet abgestürzt');

        $this->get('/t/aufgaben')->assertRedirect(route('student-test.start'));
        $this->post('/t/login', ['login_code' => $this->loginCode->login_code])
            ->assertRedirect(route('student-test.instructions'));

        $new = TestAttempt::query()->latest('id')->firstOrFail();
        $this->assertNotSame($attempt->id, $new->id);
        $this->assertNull($new->main_started_at);
    }
}
