<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Crypto\CryptoService;
use App\Domain\NoticeText\Models\NoticeText;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\PermissionResolver;
use App\Filament\Resources\TestRunResource\Pages\CreateTestRun;
use App\Filament\Resources\TestRunResource\Pages\ListTestRuns;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultNoticeTextSeeder;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sichert, dass die Testlauf-Liste rendert (u. a. mit dem Aktionen-Dropdown
 * und dem scopebaren modifyQueryUsing-Filter).
 */
class TestRunListRenderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_run_list_renders_for_admin(): void
    {
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'BSP']);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($admin, 'pw-1234567890');
        $this->actingAs($admin);

        Livewire::test(ListTestRuns::class)->assertOk();
    }

    #[Test]
    public function create_form_preselects_default_notice_text(): void
    {
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class, DefaultNoticeTextSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'BSP']);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($admin, 'pw-1234567890');
        $this->actingAs($admin);

        Livewire::test(CreateTestRun::class)
            ->assertOk()
            ->assertFormSet(['notice_text_id' => NoticeText::defaultText()->id]);
    }
}
