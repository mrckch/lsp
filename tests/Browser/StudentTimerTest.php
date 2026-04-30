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

class StudentTimerTest extends DuskTestCase
{
    use DatabaseMigrations;

    private string $loginCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWithShortTimer();
    }

    private function seedWithShortTimer(): void
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
            'default_time_limit_seconds' => 5, 'practice_time_seconds' => 0,
            'created_by_user_id' => $admin->id,
        ]);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Timer-Frage 1', 'correct_answer' => 'richtig']);

        $norm = NormTable::create([
            'name' => 'N', 'grade_level' => '5', 'parallel_form' => 'A1',
            'is_active' => true, 'status' => 'aktiv', 'created_by_user_id' => $admin->id,
        ]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 0, 'quotient_male' => 60, 'quotient_female' => 65]);
        NormTableRow::create(['norm_table_id' => $norm->id, 'raw_score' => 1, 'quotient_male' => 100, 'quotient_female' => 105]);

        $run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'Timer-Test', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id, 'norm_table_id' => $norm->id,
            'time_limit_seconds' => 5, 'practice_time_seconds' => 0,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $admin->id,
        ]);
        $run->learningGroups()->attach($group->id);

        $student = new Student;
        $student->external_student_id = 'TIMER-001';
        $student->external_id_source = 'manual';
        $student->student_code = Student::generateUniqueCode();
        $student->first_name_encrypted = 'Timer';
        $student->last_name_encrypted = 'Test';
        $student->gender = 'm';
        $student->save();
        $student->memberships()->create(['learning_group_id' => $group->id, 'school_year_id' => $sy->id]);

        app(CryptoService::class)->lock();

        app(TestEngine::class)->issueLoginCodes($run);
        $this->loginCode = StudentLoginCode::query()->where('student_id', $student->id)->firstOrFail()->login_code;
    }

    #[Test]
    public function timer_ablauf_fuehrt_zu_automatischer_abgabe(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/t')
                ->type('login_code', $this->loginCode)
                ->press('Anmelden')
                ->waitForText('Hinweise zum Test')
                ->press('Test starten')
                ->waitForText('Timer-Frage 1');

            // Timer-Warnung: nach 5s → JS redirect → Server-Submit mit ended_by='system'
            $browser->waitForText('Test abgeschlossen', 15);
            $browser->assertSee('Test abgeschlossen');
        });

        $attempt = TestAttempt::query()->latest('id')->first();
        $this->assertNotNull($attempt);
        $this->assertContains($attempt->status, ['abgegeben', 'zeit_abgelaufen']);
        $this->assertEquals('system', $attempt->ended_by);
    }

    #[Test]
    public function timer_countdown_wird_angezeigt(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/t')
                ->type('login_code', $this->loginCode)
                ->press('Anmelden')
                ->waitForText('Hinweise zum Test')
                ->press('Test starten')
                ->waitForText('Timer-Frage 1');

            $timerText = $browser->text('#timer');
            $seconds = (int) filter_var($timerText, FILTER_SANITIZE_NUMBER_INT);
            $this->assertGreaterThan(0, $seconds);
            $this->assertLessThanOrEqual(5, $seconds);
        });
    }
}
