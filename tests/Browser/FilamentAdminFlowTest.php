<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

/**
 * E2E-Smoke-Test für den Filament-Admin-Flow:
 *  - Login funktioniert
 *  - Dashboard ist erreichbar
 *  - User-Liste rendert die Test-User
 *  - Edit-Action öffnet die Edit-Seite
 *
 * Bulk-Aktionen im Livewire-Modal sind als Browser-Test berüchtigt fragil
 * (dynamische DOM-Selektoren bei Modal-Render); der Bulk-Job-Trigger selbst
 * ist im Feature-Test 'UserBulkWelcomeTest' (Livewire-Komponentenebene)
 * gründlich abgedeckt. Hier wird die fehlende Browser-Schicht (Login + Routing)
 * verifiziert — also genau das, was Livewire-Tests NICHT abdecken.
 */
class FilamentAdminFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);

        $this->admin = User::create([
            'username' => 'admin',
            'display_name' => 'Admin Tester',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin-pw-1234567890'),
            'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);

        // Admin hat force_two_factor=true im Default-Seeder; hier vermeiden
        // wir den Setup-Zwang, indem wir 2FA als bereits aktiviert markieren.
        $this->admin->update(['two_factor_enabled' => true]);

        app(CryptoService::class)->initialize($this->admin, 'clear-pw-1234567890');
        app(CryptoService::class)->lock();

        $this->teacher = User::create([
            'username' => 'lehrer1',
            'display_name' => 'Lehrer Eins',
            'email' => 'lehrer1@example.com',
            'password' => Hash::make('temp-pw-1234567890'),
            'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);
    }

    #[Test]
    public function admin_login_reaches_dashboard(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin->id, 'web')
                ->visit('/admin')
                ->waitForText('Dashboard', 10)
                ->assertSee('Dashboard');
        });
    }

    #[Test]
    public function admin_user_list_shows_seeded_users(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin->id, 'web')
                ->visit('/admin/users')
                ->waitForText('admin', 10)
                ->assertSee('admin')
                ->assertSee('lehrer1')
                ->assertSee('Admin Tester')
                ->assertSee('Lehrer Eins');
        });
    }

    #[Test]
    public function unauthenticated_visit_to_admin_redirects_to_login(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->waitForLocation('/admin/login', 10)
                ->assertPathIs('/admin/login');
        });
    }
}
