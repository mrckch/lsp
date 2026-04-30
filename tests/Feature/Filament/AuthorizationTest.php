<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Permission\Models\Permission;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserPermissionOverride;
use App\Domain\Permission\PermissionResolver;
use App\Domain\School\Models\SchoolYear;
use App\Filament\Resources\NormTableResource;
use App\Filament\Resources\PrintTemplateResource;
use App\Filament\Resources\SchoolYearResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
    private User $secretariat;
    private SchoolYear $sy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);

        $this->teacher = User::create([
            'username' => 't', 'display_name' => 'T',
            'password' => Hash::make('teacher-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);

        $this->secretariat = User::create([
            'username' => 's', 'display_name' => 'S',
            'password' => Hash::make('sek-pw-1234567890'), 'is_active' => true,
        ]);
        $this->secretariat->userGroups()->attach(UserGroup::where('name', 'Sekretariat')->first()->id);

        $this->sy = SchoolYear::create([
            'label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31',
        ]);

        // Cache leeren, damit Tests deterministisch sind
        app(PermissionResolver::class)->flush();
    }

    #[Test]
    public function admin_can_view_and_create_anything(): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(SchoolYearResource::canViewAny());
        $this->assertTrue(SchoolYearResource::canCreate());
        $this->assertTrue(StudentResource::canViewAny());
        $this->assertTrue(StudentResource::canCreate());
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::canCreate());
        $this->assertTrue(NormTableResource::canViewAny());
        $this->assertTrue(NormTableResource::canCreate());
        $this->assertTrue(PrintTemplateResource::canViewAny());
        $this->assertTrue(PrintTemplateResource::canCreate());
    }

    #[Test]
    public function teacher_cannot_create_school_years_or_users(): void
    {
        $this->actingAs($this->teacher);

        $this->assertTrue(SchoolYearResource::canViewAny());     // school_years.view
        $this->assertFalse(SchoolYearResource::canCreate());     // .manage fehlt
        $this->assertFalse(UserResource::canViewAny());          // users.view fehlt
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(PrintTemplateResource::canViewAny()); // print.templates.view fehlt
    }

    #[Test]
    public function secretariat_cannot_manage_questionnaires(): void
    {
        $this->actingAs($this->secretariat);

        $this->assertTrue(StudentResource::canViewAny());        // students.view
        $this->assertTrue(StudentResource::canCreate());         // students.manage
        $this->assertFalse(NormTableResource::canViewAny());     // norm_tables.view fehlt
        $this->assertFalse(NormTableResource::canCreate());
    }

    #[Test]
    public function override_grant_overrides_class_default(): void
    {
        $perm = Permission::where('key', 'print.templates.view')->first();
        UserPermissionOverride::create([
            'user_id' => $this->teacher->id,
            'permission_id' => $perm->id,
            'mode' => 'grant',
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $this->actingAs($this->teacher);
        $this->assertTrue(PrintTemplateResource::canViewAny());
    }

    #[Test]
    public function override_revoke_takes_precedence(): void
    {
        $perm = Permission::where('key', 'students.view')->first();
        UserPermissionOverride::create([
            'user_id' => $this->teacher->id,
            'permission_id' => $perm->id,
            'mode' => 'revoke',
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $this->actingAs($this->teacher);
        $this->assertFalse(StudentResource::canViewAny());
    }

    #[Test]
    public function unauthenticated_user_cannot_view_any_resource(): void
    {
        // Kein actingAs!
        $this->assertFalse(SchoolYearResource::canViewAny());
        $this->assertFalse(StudentResource::canViewAny());
    }

    #[Test]
    public function navigation_is_hidden_when_user_lacks_permission(): void
    {
        $this->actingAs($this->teacher);

        $this->assertFalse(UserResource::shouldRegisterNavigation());      // teacher hat kein users.view
        $this->assertTrue(StudentResource::shouldRegisterNavigation());    // teacher hat students.view
    }
}
