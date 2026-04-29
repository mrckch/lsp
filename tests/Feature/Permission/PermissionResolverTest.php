<?php

declare(strict_types=1);

namespace Tests\Feature\Permission;

use App\Domain\Permission\Models\Permission;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserPermissionOverride;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private PermissionResolver $resolver;
    private User $user;
    private int $groupAId;
    private int $groupBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DefaultUserGroupsSeeder::class);

        $this->resolver = new PermissionResolver(useCache: false);

        $this->user = User::create([
            'username' => 'tester',
            'display_name' => 'Tester',
            'password' => Hash::make('pass-1234567890'),
            'is_active' => true,
        ]);

        $sy = SchoolYear::create([
            'label' => 'TEST',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
        ]);
        $this->groupAId = LearningGroup::create([
            'school_year_id' => $sy->id, 'name' => 'A', 'group_type' => 'klasse',
        ])->id;
        $this->groupBId = LearningGroup::create([
            'school_year_id' => $sy->id, 'name' => 'B', 'group_type' => 'klasse',
        ])->id;
    }

    #[Test]
    public function user_without_groups_has_no_permissions(): void
    {
        $this->assertFalse($this->resolver->can($this->user, 'students.view'));
    }

    #[Test]
    public function class_permissions_are_inherited(): void
    {
        $lehrkraft = UserGroup::query()->where('name', 'Lehrkraft')->firstOrFail();
        $this->user->userGroups()->attach($lehrkraft->id);

        $this->assertTrue($this->resolver->can($this->user, 'students.view'));
        $this->assertTrue($this->resolver->can($this->user, 'test_runs.create'));
        $this->assertFalse($this->resolver->can($this->user, 'system.backup.run'));
    }

    #[Test]
    public function user_grant_adds_extra_permission(): void
    {
        $lehrkraft = UserGroup::query()->where('name', 'Lehrkraft')->firstOrFail();
        $this->user->userGroups()->attach($lehrkraft->id);

        $perm = Permission::query()->where('key', 'system.audit.view')->firstOrFail();
        UserPermissionOverride::create([
            'user_id' => $this->user->id,
            'permission_id' => $perm->id,
            'mode' => 'grant',
        ]);

        $this->assertTrue($this->resolver->can($this->user, 'system.audit.view'));
    }

    #[Test]
    public function user_revoke_removes_class_permission(): void
    {
        $lehrkraft = UserGroup::query()->where('name', 'Lehrkraft')->firstOrFail();
        $this->user->userGroups()->attach($lehrkraft->id);

        $perm = Permission::query()->where('key', 'clearname.unlock')->firstOrFail();
        UserPermissionOverride::create([
            'user_id' => $this->user->id,
            'permission_id' => $perm->id,
            'mode' => 'revoke',
        ]);

        $this->assertFalse($this->resolver->can($this->user, 'clearname.unlock'));
    }

    #[Test]
    public function user_with_no_scopes_is_unscoped(): void
    {
        $this->assertNull($this->resolver->scopeLearningGroupIds($this->user));
    }

    #[Test]
    public function user_with_scopes_is_restricted(): void
    {
        UserScopeAssignment::create(['user_id' => $this->user->id, 'learning_group_id' => $this->groupAId]);
        UserScopeAssignment::create(['user_id' => $this->user->id, 'learning_group_id' => $this->groupBId]);

        $this->assertEquals(
            [$this->groupAId, $this->groupBId],
            $this->resolver->scopeLearningGroupIds($this->user),
        );
    }

    #[Test]
    public function scoped_permission_check_respects_scope(): void
    {
        $lehrkraft = UserGroup::query()->where('name', 'Lehrkraft')->firstOrFail();
        $this->user->userGroups()->attach($lehrkraft->id);

        UserScopeAssignment::create(['user_id' => $this->user->id, 'learning_group_id' => $this->groupAId]);

        $this->assertTrue($this->resolver->canForLearningGroup($this->user, 'students.view', $this->groupAId));
        $this->assertFalse($this->resolver->canForLearningGroup($this->user, 'students.view', $this->groupBId));
    }

    #[Test]
    public function non_scopeable_permission_is_global(): void
    {
        $admin = UserGroup::query()->where('name', 'Admin')->firstOrFail();
        $this->user->userGroups()->attach($admin->id);

        UserScopeAssignment::create(['user_id' => $this->user->id, 'learning_group_id' => $this->groupAId]);

        // system.backup.run ist nicht scopeable → Scope wird ignoriert
        $this->assertTrue($this->resolver->canForLearningGroup($this->user, 'system.backup.run', $this->groupBId));
    }

    #[Test]
    public function admin_class_has_all_permissions(): void
    {
        $admin = UserGroup::query()->where('name', 'Admin')->firstOrFail();
        $this->user->userGroups()->attach($admin->id);

        $allKeys = Permission::query()->pluck('key');
        foreach ($allKeys as $key) {
            $this->assertTrue($this->resolver->can($this->user, $key), "Admin sollte $key haben");
        }
    }
}
