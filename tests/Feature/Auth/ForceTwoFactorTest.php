<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Permission\Models\UserGroup;
use App\Filament\Pages\ForceTwoFactorSetup;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForceTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);
    }

    private function makeUser(bool $twoFactorEnabled = false): User
    {
        return User::create([
            'username' => 'u'.random_int(1000, 9999),
            'display_name' => 'U',
            'password' => Hash::make('pw-1234567890'),
            'is_active' => true,
            'two_factor_enabled' => $twoFactorEnabled,
        ]);
    }

    #[Test]
    public function admin_class_user_without_2fa_is_redirected_to_force_setup(): void
    {
        // Admin-Klasse hat per Seeder force_two_factor=true
        $user = $this->makeUser();
        $user->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($user);

        $this->get('/admin')->assertRedirect('/admin/force-two-factor-setup');
    }

    #[Test]
    public function admin_with_2fa_is_not_redirected(): void
    {
        $user = $this->makeUser(twoFactorEnabled: true);
        $user->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($user);

        $this->get('/admin')->assertStatus(200);
    }

    #[Test]
    public function lehrkraft_without_2fa_is_not_redirected(): void
    {
        // Lehrkraft-Klasse hat force_two_factor=false
        $user = $this->makeUser();
        $user->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);
        $this->actingAs($user);

        $this->get('/admin')->assertStatus(200);
    }

    #[Test]
    public function user_in_class_with_force_two_factor_true_is_redirected_even_without_admin_class(): void
    {
        // Eigene Klasse mit force_two_factor=true
        $klasse = UserGroup::create([
            'name' => 'Custom-Force', 'is_system' => false,
            'force_two_factor' => true, 'sort_order' => 99,
        ]);
        $user = $this->makeUser();
        $user->userGroups()->attach($klasse->id);
        $this->actingAs($user);

        $this->get('/admin')->assertRedirect('/admin/force-two-factor-setup');
    }

    #[Test]
    public function force_setup_page_itself_is_accessible(): void
    {
        $user = $this->makeUser();
        $user->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($user);

        $this->get('/admin/force-two-factor-setup')->assertStatus(200);
    }

    #[Test]
    public function password_change_page_is_accessible_even_with_force_two_factor_pending(): void
    {
        // Wenn beides fällig ist, soll der User Password-Change machen können
        $user = $this->makeUser();
        $user->update(['must_change_password' => true]);
        $user->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($user);

        // Force-Password-Change-Middleware leitet zuerst dorthin → 2FA-Middleware lässt diesen Pfad durch
        $this->get('/admin/force-password-change')->assertStatus(200);
    }

    #[Test]
    public function can_access_force_page_only_when_required_and_not_yet_enabled(): void
    {
        // Lehrkraft (kein force) → keine Access
        $teacher = $this->makeUser();
        $teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);
        $this->actingAs($teacher);
        $this->assertFalse(ForceTwoFactorSetup::canAccess());

        // Admin ohne 2FA → access
        $admin = $this->makeUser();
        $admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($admin->refresh());
        $this->assertTrue(ForceTwoFactorSetup::canAccess());

        // Admin mit 2FA → keine Access (Setup nicht mehr nötig)
        $admin->update(['two_factor_enabled' => true]);
        $this->actingAs($admin->refresh());
        $this->assertFalse(ForceTwoFactorSetup::canAccess());
    }
}
