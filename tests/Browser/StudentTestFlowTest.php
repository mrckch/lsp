<?php

declare(strict_types=1);

namespace Tests\Browser;

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
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class StudentTestFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    private string $loginCode;

    private int $timeLimitSeconds = 180;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTestData();
    }

    private function seedTestData(int $timeLimitOverride = 0): void
    {
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class, DefaultAssessmentTypesSeeder::class]);

        AppSetting::singleton()->update(['is_initialized' => true, 'initialized_at' => now()]);

        $admin = User::create([
            'username' => 'admin', 'display_name' => 'Admin',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($admin, 'clear-pw-1234567890');

        $sy = SchoolYear::create(['label' => '2026/27', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $group = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);

        $q = Questionnaire::create([
            'name' => 'A1', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'default_time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'created_by_user_id' => $admin->id,
        ]);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Bananen sind gelb', 'correct_answer' => 'richtig']);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 2, 'question_text' => 'Fische leben auf Bäumen', 'correct_answer' => 'falsch']);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 3, 'question_text' => 'Wasser ist nass', 'correct_answer' => 'richtig']);

        $norm = NormTable::create([
            'name' => 'Norm-5-A1', 'grade_level' => '5', 'parallel_form' => 'A1',
            'is_active' => true, 'status' => 'aktiv', 'created_by_user_id' => $admin->id,
        ]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 0, 'quotient_male' => 60, 'quotient_female' => 65]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 1, 'quotient_male' => 80, 'quotient_female' => 85]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 2, 'quotient_male' => 95, 'quotient_female' => 100]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 3, 'quotient_male' => 110, 'quotient_female' => 115]);

        $timeLimit = $timeLimitOverride > 0 ? $timeLimitOverride : 180;
        $this->timeLimitSeconds = $timeLimit;

        $run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'Dusk-Testlauf', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id, 'norm_table_id' => $norm->id,
            'time_limit_seconds' => $timeLimit, 'practice_time_seconds' => 0,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $admin->id,
        ]);
        $run->learningGroups()->attach($group->id);

        $student = new Student;
        $student->external_student_id = 'DUSK-001';
        $student->external_id_source = 'manual';
        $student->student_code = Student::generateUniqueCode();
        $student->first_name_encrypted = 'Max';
        $student->last_name_encrypted = 'Mustermann';
        $student->gender = 'm';
        $student->save();
        $student->memberships()->create(['learning_group_id' => $group->id, 'school_year_id' => $sy->id]);

        app(CryptoService::class)->lock();

        app(TestEngine::class)->issueLoginCodes($run);
        $this->loginCode = StudentLoginCode::query()->where('student_id', $student->id)->firstOrFail()->login_code;
    }

    #[Test]
    public function ungueltiger_code_zeigt_fehlermeldung(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/t')
                ->assertSee('Willkommen zum Lese-Test')
                ->type('login_code', 'XXXXXXXXXX')
                ->press('Anmelden')
                ->waitForText('Code unbekannt')
                ->assertSee('Code unbekannt');
        });
    }

    #[Test]
    public function vollstaendiger_test_flow(): void
    {
        $this->browse(function (Browser $browser) {
            // 1. Startseite → Code eingeben
            $browser->visit('/t')
                ->assertSee('Willkommen zum Lese-Test')
                ->assertSee('10-stelligen Zugangscode')
                ->type('login_code', $this->loginCode)
                ->press('Anmelden');

            // 2. Hinweise-Seite
            $browser->waitForText('Hinweise zum Test')
                ->assertSee('Hinweise zum Test')
                ->assertSee($this->timeLimitSeconds.' Sekunden')
                ->press('Test starten');

            // 3. Aufgaben-Seite mit Timer
            $browser->waitForText('Bananen sind gelb')
                ->assertSee('Bananen sind gelb')
                ->assertSee('Fische leben auf Bäumen')
                ->assertSee('Wasser ist nass')
                ->assertVisible('#timer');

            // 4. Antworten per Klick (AJAX) — 2 richtig, 1 falsch
            $questions = $browser->elements('.question[data-qid]');
            $qids = array_map(fn ($el) => $el->getAttribute('data-qid'), $questions);

            $browser->click(".question[data-qid='{$qids[0]}'] button[data-answer='richtig']");
            $browser->pause(300);
            $browser->click(".question[data-qid='{$qids[1]}'] button[data-answer='falsch']");
            $browser->pause(300);
            $browser->click(".question[data-qid='{$qids[2]}'] button[data-answer='richtig']");
            $browser->pause(300);

            // Erste Antwort sollte als „active" markiert sein
            $browser->assertPresent(".question[data-qid='{$qids[0]}'] button[data-answer='richtig'].active");

            // 5. Test abgeben
            $browser->press('Test abgeben');

            // 6. Ergebnis-Seite
            $browser->waitForText('Test abgeschlossen')
                ->assertSee('Test abgeschlossen')
                ->assertSee('Richtige Antworten')
                ->assertSee('Lesequotient (LQ)');
        });

        // DB-Assertions
        $attempt = TestAttempt::query()->latest('id')->first();
        $this->assertNotNull($attempt);
        $this->assertEquals('abgegeben', $attempt->status);
        // 3 Antworten richtig (Q1: richtig, Q2: falsch, Q3: richtig — alle korrekt)
        $this->assertEquals(3, $attempt->score_raw);
        $this->assertEquals(110, $attempt->lq_current); // raw 3, gender m → 110

        $code = StudentLoginCode::query()->where('login_code', $this->loginCode)->first();
        $this->assertEquals('verbraucht', $code->status);
    }

    #[Test]
    public function code_per_query_parameter_wird_vorausgefuellt(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/t?code='.$this->loginCode)
                ->assertInputValue('login_code', $this->loginCode);
        });
    }

    #[Test]
    public function session_redirect_bei_laufendem_test(): void
    {
        $this->browse(function (Browser $browser) {
            // Login starten
            $browser->visit('/t')
                ->type('login_code', $this->loginCode)
                ->press('Anmelden')
                ->waitForText('Hinweise zum Test')
                ->press('Test starten')
                ->waitForText('Bananen sind gelb');

            // Nochmal /t aufrufen → sollte zu Aufgaben zurückleiten
            $browser->visit('/t')
                ->waitForText('Bananen sind gelb')
                ->assertSee('Bananen sind gelb');
        });
    }

    #[Test]
    public function ergebnis_ohne_score_wenn_deaktiviert(): void
    {
        // TestRun auf show_score_to_student = false setzen
        TestRun::query()->update(['show_score_to_student' => false]);

        $this->browse(function (Browser $browser) {
            $browser->visit('/t')
                ->type('login_code', $this->loginCode)
                ->press('Anmelden')
                ->waitForText('Hinweise zum Test')
                ->press('Test starten')
                ->waitForText('Bananen sind gelb')
                ->press('Test abgeben')
                ->waitForText('Test abgeschlossen')
                ->assertSee('Vielen Dank')
                ->assertDontSee('Lesequotient');
        });
    }
}
