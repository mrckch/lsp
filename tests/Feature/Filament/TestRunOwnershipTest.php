<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\Permission;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserPermissionOverride;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Resources\TestRunResource;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TestRunOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private LearningGroup $g1;

    private LearningGroup $g2;

    private TestRun $ownRun;

    private TestRun $foreignRun;

    private TestRun $foreignRunOutOfScope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);

        // Cache-frei, damit Tests deterministisch sind
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
        $this->g1 = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $this->g2 = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5b', 'group_type' => 'klasse']);
        $this->g3 = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5c', 'group_type' => 'klasse']);

        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $base = [
            'school_year_id' => $sy->id, 'questionnaire_id' => $q->id,
            'status' => 'aktiv', 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ];

        $this->ownRun = TestRun::create([
            ...$base, 'name' => 'Own', 'short_code' => TestRun::generateShortCode(),
            'owner_user_id' => $this->teacher->id,
        ]);
        $this->ownRun->learningGroups()->attach($this->g1->id);

        $this->foreignRun = TestRun::create([
            ...$base, 'name' => 'Foreign', 'short_code' => TestRun::generateShortCode(),
            'owner_user_id' => $this->admin->id,
        ]);
        $this->foreignRun->learningGroups()->attach($this->g1->id);

        $this->foreignRunOutOfScope = TestRun::create([
            ...$base, 'name' => 'OutOfScope', 'short_code' => TestRun::generateShortCode(),
            'owner_user_id' => $this->admin->id,
        ]);
        $this->foreignRunOutOfScope->learningGroups()->attach($this->g3->id);

        // Lehrer-Scope auf g1 beschränken
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id, 'learning_group_id' => $this->g1->id,
        ]);
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id, 'learning_group_id' => $this->g2->id,
        ]);
        // g3 NICHT zuweisen → out-of-scope

        app(PermissionResolver::class)->flush();
    }

    private $g3;

    #[Test]
    public function teacher_can_edit_own_run_in_scope(): void
    {
        $this->actingAs($this->teacher);
        $this->assertTrue(TestRunResource::canEdit($this->ownRun));
    }

    #[Test]
    public function teacher_cannot_edit_foreign_run_without_manage_all(): void
    {
        $this->actingAs($this->teacher);
        // Lehrer hat manage_own aber kein manage_all → fremder Run blockiert
        $this->assertFalse(TestRunResource::canEdit($this->foreignRun));
    }

    #[Test]
    public function teacher_can_edit_foreign_run_with_manage_all_grant(): void
    {
        $perm = Permission::where('key', 'test_runs.manage_all')->first();
        UserPermissionOverride::create([
            'user_id' => $this->teacher->id,
            'permission_id' => $perm->id,
            'mode' => 'grant',
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $this->actingAs($this->teacher);
        $this->assertTrue(TestRunResource::canEdit($this->foreignRun));
    }

    #[Test]
    public function teacher_cannot_edit_run_outside_scope_even_as_owner(): void
    {
        $this->foreignRunOutOfScope->update(['owner_user_id' => $this->teacher->id]);

        $this->actingAs($this->teacher);
        $this->assertFalse(TestRunResource::canEdit($this->foreignRunOutOfScope));
    }

    #[Test]
    public function admin_can_edit_anything(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(TestRunResource::canEdit($this->ownRun));
        $this->assertTrue(TestRunResource::canEdit($this->foreignRun));
        $this->assertTrue(TestRunResource::canEdit($this->foreignRunOutOfScope));
    }

    #[Test]
    public function delete_requires_both_delete_permission_and_can_edit(): void
    {
        $this->actingAs($this->teacher);
        // Lehrer hat KEIN test_runs.delete (Default-Klasse) → false
        $this->assertFalse(TestRunResource::canDelete($this->ownRun));

        $this->actingAs($this->admin);
        $this->assertTrue(TestRunResource::canDelete($this->ownRun));
    }
}
