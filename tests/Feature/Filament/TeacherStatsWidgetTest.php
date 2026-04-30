<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Widgets\TeacherStats;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeacherStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private SchoolYear $sy;

    private LearningGroup $g5a;

    private LearningGroup $g5b;

    private TestRun $run5a;

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

        $this->sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $this->g5a = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $this->g5b = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5b', 'group_type' => 'klasse']);

        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->run5a = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => '5a-Run',
            'short_code' => TestRun::generateShortCode(), 'status' => 'aktiv',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run5a->learningGroups()->attach($this->g5a->id);

        $run5b = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => '5b-Run',
            'short_code' => TestRun::generateShortCode(), 'status' => 'aktiv',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $run5b->learningGroups()->attach($this->g5b->id);

        // 2 SuS in 5a (LQs 90, 110), 1 SuS in 5b (LQ 80)
        foreach ([['Anna', $this->g5a, $this->run5a, 90], ['Bob', $this->g5a, $this->run5a, 110],
            ['Carl', $this->g5b, $run5b, 80]] as [$name, $g, $run, $lq]) {
            $s = new Student;
            $s->external_student_id = $name;
            $s->external_id_source = 'manual';
            $s->student_code = Student::generateUniqueCode();
            $s->first_name_encrypted = $name;
            $s->last_name_encrypted = $name;
            $s->gender = 'w';
            $s->save();
            $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $this->sy->id]);
            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $run->id,
                'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                'started_at' => now(), 'submitted_at' => now(),
                'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => $lq, 'lq_current' => $lq,
            ]);
        }
    }

    private function callStats(): array
    {
        $widget = new TeacherStats;
        $ref = new \ReflectionMethod($widget, 'getStats');
        $ref->setAccessible(true);

        return $ref->invoke($widget);
    }

    #[Test]
    public function widget_visibility_requires_students_view(): void
    {
        // Admin hat alles
        $this->actingAs($this->admin);
        $this->assertTrue(TeacherStats::canView());

        // User ohne Klasse → keine students.view → kein Widget
        $noPerm = User::create([
            'username' => 'np', 'display_name' => 'NP',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);
        $this->actingAs($noPerm);
        $this->assertFalse(TeacherStats::canView());
    }

    #[Test]
    public function admin_sees_global_counts(): void
    {
        $this->actingAs($this->admin);
        $stats = $this->callStats();

        // 2 Lerngruppen, 3 SuS, 2 Runs
        $this->assertEquals('2', $stats[0]->getValue());
        $this->assertEquals('3', $stats[1]->getValue());
        $this->assertEquals('2', $stats[2]->getValue());
    }

    #[Test]
    public function teacher_with_scope_sees_only_own_groups(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id, 'learning_group_id' => $this->g5a->id,
        ]);

        $this->actingAs($this->teacher);
        $stats = $this->callStats();

        $this->assertEquals('1', $stats[0]->getValue()); // 1 Lerngruppe
        $this->assertEquals('2', $stats[1]->getValue()); // 2 SuS in 5a
        $this->assertEquals('1', $stats[2]->getValue()); // 1 aktiver Run im Scope
        // Avg LQ Anna+Bob = 100
        $this->assertEquals('100', $stats[3]->getValue());
    }

    #[Test]
    public function avg_lq_is_dash_when_no_attempts(): void
    {
        TestAttempt::query()->delete();
        $this->actingAs($this->admin);
        $stats = $this->callStats();

        $this->assertEquals('–', $stats[3]->getValue());
    }
}
