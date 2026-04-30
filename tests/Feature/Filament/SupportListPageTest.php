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
use App\Domain\SupportThreshold\Models\SupportThreshold;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Pages\SupportListPage;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportListPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
    private SchoolYear $sy;
    private LearningGroup $g5a;
    private LearningGroup $g5b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'BSP']);
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
        $this->g5a = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse', 'grade_level' => '5']);
        $this->g5b = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5b', 'group_type' => 'klasse', 'grade_level' => '5']);
        $g6a = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '6a', 'group_type' => 'klasse', 'grade_level' => '6']);

        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        $run = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Schwellen
        SupportThreshold::create([
            'name' => 'LQ < 70', 'metric' => 'lq_absolute', 'operator' => 'lt',
            'value' => 70, 'severity' => 'foerderbedarf', 'is_active' => true,
        ]);
        SupportThreshold::create([
            'name' => 'LQ < 85', 'metric' => 'lq_absolute', 'operator' => 'lt',
            'value' => 85, 'severity' => 'auffaellig', 'is_active' => true,
        ]);

        // Schüler:
        // - Alex (5a, LQ 60) → foerderbedarf
        // - Bob  (5b, LQ 80) → auffaellig
        // - Carl (6a, LQ 65) → foerderbedarf
        // - Dora (5a, LQ 110) → kein Treffer
        foreach ([['Alex', $this->g5a, 60], ['Bob', $this->g5b, 80], ['Carl', $g6a, 65], ['Dora', $this->g5a, 110]] as [$name, $g, $lq]) {
            $s = $this->mkStudent($name, $g);
            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $run->id,
                'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                'started_at' => now(), 'submitted_at' => now(),
                'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => $lq, 'lq_current' => $lq,
            ]);
        }
    }

    private function mkStudent(string $name, LearningGroup $g): Student
    {
        $s = new Student;
        $s->external_student_id = $name;
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = $name;
        $s->last_name_encrypted = $name;
        $s->gender = 'w';
        $s->save();
        $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $this->sy->id]);
        $s->enrollments()->create(['school_year_id' => $this->sy->id, 'enrolled_at' => now()->toDateString()]);

        return $s;
    }

    private function pageWith(array $data): SupportListPage
    {
        $this->actingAs($this->admin);
        $component = Livewire::test(SupportListPage::class)->set('data', $data);

        return $component->instance();
    }

    #[Test]
    public function admin_can_access_page(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(SupportListPage::canAccess());
    }

    #[Test]
    public function teacher_without_analytics_permission_cannot_access(): void
    {
        $userNoPerm = User::create([
            'username' => 'np', 'display_name' => 'NP',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);
        $this->actingAs($userNoPerm);
        $this->assertFalse(SupportListPage::canAccess());
    }

    #[Test]
    public function rows_contain_all_students_with_threshold_hits(): void
    {
        $rows = $this->pageWith(['severity' => 'all'])->getRows();

        $this->assertCount(3, $rows); // Alex, Bob, Carl (Dora kein Treffer)
        // Förderbedarf zuerst, danach LQ aufsteigend
        $this->assertEquals('foerderbedarf', $rows[0]['severity']);
        $this->assertEquals(60, $rows[0]['lq']); // Alex LQ 60 zuerst
        $this->assertEquals(65, $rows[1]['lq']); // Carl LQ 65
        $this->assertEquals('auffaellig', $rows[2]['severity']); // Bob
    }

    #[Test]
    public function severity_filter_foerderbedarf_only(): void
    {
        $rows = $this->pageWith(['severity' => 'foerderbedarf'])->getRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertEquals('foerderbedarf', $r['severity']);
        }
    }

    #[Test]
    public function grade_level_filter_limits_to_grade(): void
    {
        $rows = $this->pageWith(['severity' => 'all', 'grade_level' => '5'])->getRows();
        $this->assertCount(2, $rows); // nur Alex (5a) + Bob (5b), Carl (6a) raus
    }

    #[Test]
    public function teacher_with_scope_only_sees_own_groups(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id, 'learning_group_id' => $this->g5a->id,
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $this->actingAs($this->teacher);
        $component = Livewire::test(SupportListPage::class)->set('data', ['severity' => 'all']);
        $rows = $component->instance()->getRows();

        $this->assertCount(1, $rows);
        $this->assertEquals('Alex', explode(' ', $rows[0]['student_name'])[0]);
    }
}
