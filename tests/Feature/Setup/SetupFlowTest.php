<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Domain\Crypto\CryptoService;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DefaultUserGroupsSeeder::class);
    }

    #[Test]
    public function setup_page_shown_when_not_initialized(): void
    {
        $response = $this->get('/setup');
        $response->assertOk();
        $response->assertSee('Erstinstallation');
    }

    #[Test]
    public function root_redirects_to_setup_when_not_initialized(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/setup');
    }

    #[Test]
    public function setup_creates_admin_app_settings_and_recovery_key(): void
    {
        $response = $this->post('/setup', [
            'school_name' => 'Beispielschule',
            'school_short_name' => 'BS',
            'admin_username' => 'admin',
            'admin_display_name' => 'Schul-Admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'sicheres-passwort-1234',
            'admin_password_confirmation' => 'sicheres-passwort-1234',
            'clearname_password' => 'klarnamen-passwort-1234',
            'clearname_password_confirmation' => 'klarnamen-passwort-1234',
            'understand_recovery' => '1',
        ]);

        $response->assertRedirect(route('setup.recovery'));

        $this->assertTrue(AppSetting::isInitialized());
        $this->assertDatabaseHas('app_settings', ['school_name' => 'Beispielschule']);
        $this->assertDatabaseHas('users', ['username' => 'admin']);

        $admin = User::where('username', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->userGroups->pluck('name')->contains('Admin'));

        // CryptoService hat einen Wrap erzeugt
        $crypto = app(CryptoService::class);
        $this->assertTrue($crypto->hasActiveWrap($admin));

        // Recovery-Key in Flash-Session
        $this->assertNotEmpty(session('recovery_key'));
    }

    #[Test]
    public function setup_rejects_short_passwords(): void
    {
        $response = $this->post('/setup', [
            'school_name' => 'Schule',
            'admin_username' => 'admin',
            'admin_display_name' => 'A',
            'admin_password' => 'short',
            'admin_password_confirmation' => 'short',
            'clearname_password' => 'short',
            'clearname_password_confirmation' => 'short',
            'understand_recovery' => '1',
        ]);

        $response->assertSessionHasErrors(['admin_password', 'clearname_password']);
        $this->assertFalse(AppSetting::isInitialized());
    }

    #[Test]
    public function setup_blocked_when_already_initialized(): void
    {
        AppSetting::singleton()->update(['is_initialized' => true, 'initialized_at' => now()]);

        $response = $this->get('/setup');
        $response->assertRedirect('/admin');
    }
}
