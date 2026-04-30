<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserClearnameAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private CryptoService $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);

        $this->admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);

        $this->teacher = User::create([
            'username' => 'lehrer', 'display_name' => 'L',
            'password' => Hash::make('lehrer-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);

        $this->crypto = app(CryptoService::class);
        $this->crypto->initialize($this->admin, 'clearname-pw-1234567890');
        $this->actingAs($this->admin);
    }

    #[Test]
    public function admin_can_provision_clearname_access_for_teacher(): void
    {
        $this->assertFalse($this->crypto->hasActiveWrap($this->teacher));

        Livewire::test(ListUsers::class)
            ->callTableAction('provisionClearname', $this->teacher, [
                'initial_password' => 'init-clearname-pw-1234567890',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($this->crypto->hasActiveWrap($this->teacher));
    }

    #[Test]
    public function provision_action_blocks_when_session_locked(): void
    {
        $this->crypto->lock();

        Livewire::test(ListUsers::class)
            ->callTableAction('provisionClearname', $this->teacher, [
                'initial_password' => 'init-clearname-pw-1234567890',
            ]);

        // Session war gesperrt → kein Wrap angelegt
        $this->assertFalse($this->crypto->hasActiveWrap($this->teacher));
    }

    #[Test]
    public function provision_action_hidden_when_user_already_has_wrap(): void
    {
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('provisionClearname', $this->teacher);
    }

    #[Test]
    public function admin_can_revoke_clearname_access(): void
    {
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');
        $this->assertTrue($this->crypto->hasActiveWrap($this->teacher));

        Livewire::test(ListUsers::class)
            ->callTableAction('revokeClearname', $this->teacher)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($this->crypto->hasActiveWrap($this->teacher));
    }

    #[Test]
    public function revoke_action_hidden_when_user_has_no_wrap(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('revokeClearname', $this->teacher);
    }

    #[Test]
    public function provision_and_revoke_hidden_for_user_without_permission(): void
    {
        // Schulleitung hat NICHT clearname.password.provision/revoke (nur Admin)
        $schulleitung = User::create([
            'username' => 'sl', 'display_name' => 'SL',
            'password' => Hash::make('sl-pw-1234567890'), 'is_active' => true,
        ]);
        $schulleitung->userGroups()->attach(UserGroup::where('name', 'Schulleitung')->first()->id);
        $this->actingAs($schulleitung);

        // Lehrer mit Wrap (für Revoke-Test) und Lehrer ohne Wrap (für Provision-Test)
        $teacherWithWrap = $this->teacher;
        $this->crypto->provisionWrapForUser($teacherWithWrap, 'init-pw-1234567890');

        $teacherWithoutWrap = User::create([
            'username' => 'l2', 'display_name' => 'L2',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('provisionClearname', $teacherWithoutWrap)
            ->assertTableActionHidden('revokeClearname', $teacherWithWrap);
    }
}
