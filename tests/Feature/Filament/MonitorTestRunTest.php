<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\SupportThreshold\Models\SupportThreshold;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Resources\TestRunResource;
use App\Filament\Resources\TestRunResource\Pages\MonitorTestRun;
use App\Filament\Resources\TestRunResource\Widgets\TestRunLqBoxplot;
use App\Filament\Widgets\ActiveTestRunsOverview;
use App\Filament\Widgets\AuditStats;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultSupportThresholdsSeeder;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorTestRunTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private TestRun $run;

    private TestRun $foreignRun;

    private StudentLoginCode $code5a;

    private StudentLoginCode $code5b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true]);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $this->teacher = User::create([
            'username' => 't', 'display_name' => 'T',
            'password' => Hash::make('teacher-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g5a = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $g5b = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5b', 'group_type' => 'klasse']);
        $g5c = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5c', 'group_type' => 'klasse']);

        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'S1', 'correct_answer' => 'richtig']);
        QuestionnaireQuestion::create(['questionnaire_id' => $q->id, 'sort_order' => 2, 'question_text' => 'S2', 'correct_answer' => 'falsch']);

        $base = [
            'school_year_id' => $sy->id, 'questionnaire_id' => $q->id,
            'status' => 'aktiv', 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id, 'owner_user_id' => $this->teacher->id,
        ];
        $this->run = TestRun::create([...$base, 'name' => 'Run', 'short_code' => TestRun::generateShortCode()]);
        $this->run->learningGroups()->attach([$g5a->id, $g5b->id]);

        $this->foreignRun = TestRun::create([...$base, 'name' => 'Fremd', 'short_code' => TestRun::generateShortCode()]);
        $this->foreignRun->learningGroups()->attach($g5c->id);

        $s5a = $this->student($g5a, $sy, 'Anna', 'Alpha');
        $s5b = $this->student($g5b, $sy, 'Bert', 'Beta');

        app(TestEngine::class)->issueLoginCodes($this->run);
        $this->code5a = StudentLoginCode::query()->where('student_id', $s5a->id)->firstOrFail();
        $this->code5b = StudentLoginCode::query()->where('student_id', $s5b->id)->firstOrFail();

        // Lehrkraft sieht nur 5a
        UserScopeAssignment::create(['user_id' => $this->teacher->id, 'learning_group_id' => $g5a->id]);
        app(PermissionResolver::class)->flush();
    }

    private function student(LearningGroup $g, SchoolYear $sy, string $first, string $last): Student
    {
        $s = new Student;
        $s->external_student_id = $first;
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = $first;
        $s->last_name_encrypted = $last;
        $s->gender = 'w';
        $s->save();
        $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);

        return $s;
    }

    private function startedAttempt(StudentLoginCode $code): TestAttempt
    {
        $engine = app(TestEngine::class);
        $attempt = $engine->startAttempt($code->student, $this->run, $code);
        $engine->beginMain($attempt);

        return $attempt->refresh();
    }

    #[Test]
    public function admin_sees_all_students_with_names_and_codes(): void
    {
        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->code5a, $this->code5b])
            ->assertSee('Anna Alpha')
            ->assertSee($this->code5a->login_code);
    }

    #[Test]
    public function teacher_sees_only_students_of_own_groups(): void
    {
        $this->actingAs($this->teacher);

        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->code5a])
            ->assertCanNotSeeTableRecords([$this->code5b]);
    }

    #[Test]
    public function teacher_cannot_open_run_outside_scope(): void
    {
        $this->actingAs($this->teacher);

        Livewire::test(MonitorTestRun::class, ['record' => $this->foreignRun->id])
            ->assertForbidden();
    }

    #[Test]
    public function reset_attempt_reactivates_code_and_logs_audit(): void
    {
        $attempt = $this->startedAttempt($this->code5a);
        app(TestEngine::class)->saveAnswer($attempt, $attempt->questionnaire->questions->first()->id, 'richtig');
        app(TestEngine::class)->submitAttempt($attempt);
        $this->actingAs($this->teacher);

        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->callTableAction('resetAttempt', $this->code5a, ['reason' => 'Tablet abgestürzt'])
            ->assertHasNoTableActionErrors();

        $attempt->refresh();
        $this->assertSame('zurueckgesetzt', $attempt->status);
        $this->assertSame('Tablet abgestürzt', $attempt->reset_reason);
        $this->assertSame(0, $attempt->answers()->count());
        $this->assertSame('aktiv', $this->code5a->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'attempts.reset')->where('entity_id', $attempt->id)->exists());
    }

    #[Test]
    public function teacher_cannot_reset_when_run_forbids_teacher_reset(): void
    {
        $this->run->update(['allow_teacher_reset' => false]);
        $this->startedAttempt($this->code5a);
        $this->actingAs($this->teacher);

        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->assertTableActionHidden('resetAttempt', $this->code5a)
            ->assertTableActionHidden('endAttempt', $this->code5a);

        // Admin (manage_all) darf trotzdem
        $this->actingAs($this->admin);
        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->assertTableActionVisible('resetAttempt', $this->code5a);
    }

    #[Test]
    public function end_attempt_submits_with_teacher_as_ender(): void
    {
        $attempt = $this->startedAttempt($this->code5a);

        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->callTableAction('endAttempt', $this->code5a);

        $attempt->refresh();
        $this->assertSame('abgegeben', $attempt->status);
        $this->assertSame('lehrkraft', $attempt->ended_by);
        $this->assertSame('verbraucht', $this->code5a->refresh()->status);
    }

    #[Test]
    public function lock_and_unlock_login_code(): void
    {
        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->callTableAction('toggleLock', $this->code5a);
        $this->assertSame('gesperrt', $this->code5a->refresh()->status);
        $this->assertNull(app(TestEngine::class)->loginByCode($this->code5a->login_code));

        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->callTableAction('toggleLock', $this->code5a);
        $this->assertSame('aktiv', $this->code5a->refresh()->status);
    }

    #[Test]
    public function show_code_modal_contains_qr_and_code(): void
    {
        Livewire::test(MonitorTestRun::class, ['record' => $this->run->id])
            ->mountTableAction('showCode', $this->code5a)
            ->assertSee($this->code5a->login_code)
            ->assertSee('<svg', false);
    }

    #[Test]
    public function dashboard_shows_one_tile_per_active_run_in_scope(): void
    {
        $this->startedAttempt($this->code5a);
        $this->actingAs($this->teacher);

        Livewire::test(ActiveTestRunsOverview::class)
            ->assertSee('Run')
            ->assertSee('0 / 1 fertig')          // Lehrkraft zählt nur 5a
            ->assertSee('1 laufen gerade')
            ->assertSee(TestRunResource::getUrl('monitor', ['record' => $this->run]))
            ->assertDontSee('Fremd');            // 5c liegt außerhalb des Scopes

        $this->actingAs($this->admin);
        Livewire::test(ActiveTestRunsOverview::class)
            ->assertSee('0 / 2 fertig')
            ->assertSee('Fremd');
    }

    #[Test]
    public function expired_unsubmitted_attempts_are_finalized_by_command(): void
    {
        $attempt = $this->startedAttempt($this->code5a);
        $running = $this->startedAttempt($this->code5b);
        $attempt->update(['main_started_at' => now()->subSeconds(180 + TestEngine::ANSWER_GRACE_SECONDS + 1)]);

        $this->artisan('attempts:finalize-expired')->assertSuccessful();

        $this->assertSame('zeit_abgelaufen', $attempt->refresh()->status);
        $this->assertSame('system', $attempt->ended_by);
        $this->assertSame('verbraucht', $this->code5a->refresh()->status);
        $this->assertSame('gestartet', $running->refresh()->status);
    }

    #[Test]
    public function boxplot_stats_use_inclusive_quartiles_and_tukey_whiskers(): void
    {
        $s = TestRunLqBoxplot::stats([70, 80, 85, 90, 95, 100, 160]);

        $this->assertSame(90.0, $s['median']);
        $this->assertSame(82.5, $s['q1']);
        $this->assertSame(97.5, $s['q3']);
        $this->assertSame(70.0, $s['lo']);
        $this->assertSame(100.0, $s['hi']);   // 160 ist Ausreißer (> Q3 + 1,5 × IQR)
        $this->assertSame(2, $s['below']);    // 70 und 80 unter 85
    }

    #[Test]
    public function support_bands_come_from_active_lq_thresholds_with_counts(): void
    {
        $this->seed(DefaultSupportThresholdsSeeder::class); // < 85 auffällig, < 70 Förderbedarf, Δ-Regel
        SupportThreshold::create(['name' => 'inaktiv', 'metric' => 'lq_absolute', 'operator' => 'lt', 'value' => 100, 'severity' => 'hinweis', 'is_active' => false]);
        SupportThreshold::create(['name' => 'doppelt', 'metric' => 'lq_absolute', 'operator' => 'le', 'value' => 69, 'severity' => 'hinweis', 'is_active' => true]);

        $bands = TestRunLqBoxplot::bands([60, 69, 70, 84, 85, 100]);

        $this->assertSame(
            [['Förderbedarf', null, 69, 2], ['auffällig', 70, 84, 2], ['unauffällig', 85, null, 2]],
            array_map(fn ($b) => [$b['label'], $b['from'], $b['to'], $b['count']], $bands),
        );
    }

    #[Test]
    public function boxplot_widget_renders_scoped_values(): void
    {
        foreach ([$this->code5a, $this->code5b] as $i => $code) {
            $a = $this->startedAttempt($code);
            $a->update(['status' => 'abgegeben', 'lq_current' => 80 + $i * 20, 'score_raw' => 40]);
        }
        $this->actingAs($this->teacher);

        Livewire::test(TestRunLqBoxplot::class, ['record' => $this->run])
            ->assertSee('Verteilung LQ')
            ->assertSee('n = 1')                 // nur 5a im Scope
            ->assertSee('Anna Alpha: LQ 80', false)
            ->assertDontSee('Bert Beta')
            ->assertDontSee('Mädchen: n')
            ->toggle('byGender')
            ->assertSee('Mädchen:')
            ->assertSee('n = 1 · Median 80', false)
            ->assertDontSee('SuS')
            ->toggle('showBands')
            ->assertSee('unauffällig')
            ->assertSee('SuS');
    }

    #[Test]
    public function audit_tiles_link_to_prefiltered_audit_log(): void
    {
        $url = AuditStats::auditLogUrl(['students.delete', 'students.archive']);
        $this->assertStringContainsString('audit-log', $url);
        $this->assertStringContainsString(urlencode('students.delete|students.archive'), $url);

        $this->assertStringContainsString('clearnames', AuditStats::auditLogUrl(null, true));
    }
}
