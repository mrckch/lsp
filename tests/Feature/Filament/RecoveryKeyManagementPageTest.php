<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Crypto\CryptoService;
use App\Domain\Crypto\Models\RecoveryKey;
use App\Domain\Permission\Models\UserGroup;
use App\Filament\Pages\RecoveryKeyManagement;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecoveryKeyManagementPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        app(CryptoService::class)->initialize($this->admin, 'clear-pw-1234567890');
        $this->actingAs($this->admin);
    }

    #[Test]
    public function admin_can_access_page(): void
    {
        $this->assertTrue(RecoveryKeyManagement::canAccess());
    }

    #[Test]
    public function lehrkraft_cannot_access_page(): void
    {
        $teacher = User::create([
            'username' => 'l', 'display_name' => 'L',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);
        $teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);
        $this->actingAs($teacher);

        $this->assertFalse(RecoveryKeyManagement::canAccess());
    }

    #[Test]
    public function regenerate_creates_new_key_visible_in_page_state(): void
    {
        $component = Livewire::test(RecoveryKeyManagement::class)
            ->callAction('regenerate');

        $newKey = $component->get('newRecoveryKey');
        $this->assertNotNull($newKey);
        $this->assertMatchesRegularExpression('/^[A-Z2-9-]+$/', $newKey);

        // 1 widerrufen + 1 aktiv
        $this->assertEquals(1, RecoveryKey::query()->whereNotNull('revoked_at')->count());
        $this->assertEquals(1, RecoveryKey::query()->whereNull('revoked_at')->count());
    }

    #[Test]
    public function regenerate_blocked_when_session_locked(): void
    {
        app(CryptoService::class)->lock();

        $component = Livewire::test(RecoveryKeyManagement::class)
            ->callAction('regenerate');

        $this->assertNull($component->get('newRecoveryKey'));
        // Originalstand: nur 1 Recovery-Key (vom Setup), nicht widerrufen
        $this->assertEquals(1, RecoveryKey::query()->whereNull('revoked_at')->count());
    }

    #[Test]
    public function dismiss_action_clears_displayed_key(): void
    {
        Livewire::test(RecoveryKeyManagement::class)
            ->callAction('regenerate')
            ->assertSet('newRecoveryKey', fn ($v) => $v !== null)
            ->callAction('dismissKey')
            ->assertSet('newRecoveryKey', null);
    }

    #[Test]
    public function get_recovery_keys_returns_status_overview(): void
    {
        // Aus Setup: 1 aktiver Key
        $rows = (new RecoveryKeyManagement)->getRecoveryKeys();
        $this->assertCount(1, $rows);
        $this->assertEquals('active', $rows[0]['status']);
        $this->assertEquals('Initial-Recovery-Key', $rows[0]['label']);
    }
}
