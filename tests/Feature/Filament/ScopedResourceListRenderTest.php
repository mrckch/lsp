<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\PermissionResolver;
use App\Filament\Resources\LearningGroupResource\Pages\ListLearningGroups;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Filament\Resources\TestRunResource\Pages\ListTestRuns;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: Listen mit einem `modifyQueryUsing`-Scope UND Filtern crashten mit
 * "Cannot use ::class on null". Filament reicht die Query per Parameternamen `query`
 * durch — ein Closure-Parameter `$q` wurde nicht befüllt, sodass ein model-loser
 * Builder in die Filter-Form lief. Diese Seiten müssen (auch bei leerem Bestand) laden.
 */
class ScopedResourceListRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
    }

    /**
     * @return list<array{0: class-string}>
     */
    public static function scopedListPages(): array
    {
        return [
            'Lerngruppen' => [ListLearningGroups::class],
            'Schüler' => [ListStudents::class],
            'Testläufe' => [ListTestRuns::class],
        ];
    }

    #[Test]
    #[DataProvider('scopedListPages')]
    public function scoped_list_page_renders_when_empty(string $page): void
    {
        $this->actingAs($this->admin);

        Livewire::test($page)->assertOk();
    }
}
