<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Crypto\CryptoService;
use App\Domain\Import\Adapters\SchildCsvImporter;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\PermissionResolver;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Filament\Pages\ImportWizardPage;
use App\Filament\Resources\ImportJobResource\Pages\ListImportJobs;
use App\Filament\Resources\ImportJobResource\Pages\ViewImportJob;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportWizardProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'BSP', 'school_short_name' => 'BSP']);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $this->schoolYear = SchoolYear::create([
            'label' => '2026/27', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31', 'is_active' => true,
        ]);
    }

    private function makeDiffJobId(int $count): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $h = fopen($tmp, 'w');
        fputcsv($h, ['ID', 'Name', 'Vorname', 'Klasse', 'Geschlecht'], ';', '"', '');
        for ($i = 1; $i <= $count; $i++) {
            fputcsv($h, [(string) (2000 + $i), 'N'.$i, 'V'.$i, '5a', 'w'], ';', '"', '');
        }
        fclose($h);

        return app(SchildCsvImporter::class)
            ->diff(new ImportInput(filePath: $tmp, filename: 'test.csv'), $this->schoolYear->id, 'klasse')
            ->importJobId;
    }

    #[Test]
    public function commit_starts_progress_and_chunks_to_completion(): void
    {
        $jobId = $this->makeDiffJobId(4);

        $component = Livewire::test(ImportWizardPage::class)
            ->set('jobId', $jobId)
            ->call('startImport')
            ->assertSet('committing', true)
            ->assertSet('total', 4);

        // wire:poll ruft processCommitChunk wiederholt auf, bis fertig.
        $guard = 0;
        while ($component->get('committing') && $guard++ < 20) {
            $component->call('processCommitChunk');
        }

        $component->assertSet('committing', false);
        $this->assertEquals(4, Student::count());
    }

    #[Test]
    public function import_history_pages_render(): void
    {
        $jobId = $this->makeDiffJobId(2);
        app(SchildCsvImporter::class)->commitChunk($jobId, 10);

        Livewire::test(ListImportJobs::class)->assertOk();
        Livewire::test(ViewImportJob::class, ['record' => $jobId])->assertOk();
    }
}
