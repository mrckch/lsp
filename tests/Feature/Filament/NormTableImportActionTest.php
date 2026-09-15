<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;
use App\Domain\Permission\Models\UserGroup;
use App\Filament\Resources\NormTableResource\Pages\ListNormTables;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NormTableImportActionTest extends TestCase
{
    use RefreshDatabase;

    private NormTable $normTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);

        $admin = User::create([
            'username' => 'admin', 'display_name' => 'Admin',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($admin);

        $this->normTable = NormTable::create([
            'name' => 'Klasse 5 A1', 'grade_level' => '5', 'parallel_form' => 'A1',
            'created_by_user_id' => $admin->id,
        ]);

        Storage::fake('local');
    }

    /** @return array<int, array{int, int, ?int}> Rohwert => [männlich, weiblich, divers] */
    private function storedRows(): array
    {
        return $this->normTable->rows()->orderBy('raw_score')->get()
            ->mapWithKeys(fn (NormTableRow $r) => [$r->raw_score => [$r->quotient_male, $r->quotient_female, $r->quotient_diverse]])
            ->all();
    }

    #[Test]
    public function csv_import_creates_rows_and_removes_upload(): void
    {
        NormTableRow::create([
            'norm_table_id' => $this->normTable->id, 'raw_score' => 10, 'quotient_male' => 1, 'quotient_female' => 1,
        ]);
        // UTF-8 mit BOM, Kopfzeile mit Umlaut, Windows-Zeilenenden
        $csv = "\xEF\xBB\xBFRohwert;LQ männlich;LQ weiblich;LQ divers\r\n10;85;87;86\r\n11;90;92;\r\n\r\n";
        $file = UploadedFile::fake()->createWithContent('normen.csv', $csv);

        Livewire::test(ListNormTables::class)
            ->callTableAction('importCsv', $this->normTable, data: ['csv' => $file])
            ->assertHasNoTableActionErrors()
            ->assertNotified('2 Norm-Zeilen importiert');

        $this->assertSame([10 => [85, 87, 86], 11 => [90, 92, null]], $this->storedRows());
        // Upload wird nach dem Einlesen wieder entfernt
        $this->assertSame([], Storage::disk('local')->allFiles('lsp/imports'));
    }

    #[Test]
    public function windows_1252_comma_separated_csv_is_imported(): void
    {
        $csv = mb_convert_encoding("Rohwert,LQ männlich,LQ weiblich\n5,70,72\n", 'Windows-1252', 'UTF-8');
        $file = UploadedFile::fake()->createWithContent('normen.csv', $csv);

        Livewire::test(ListNormTables::class)
            ->callTableAction('importCsv', $this->normTable, data: ['csv' => $file])
            ->assertHasNoTableActionErrors();

        $this->assertSame([5 => [70, 72, null]], $this->storedRows());
    }

    #[Test]
    public function invalid_csv_imports_nothing_and_shows_error(): void
    {
        $file = UploadedFile::fake()->createWithContent('normen.csv', "raw;m;w\n10;85;87\n11;abc;92\n");

        Livewire::test(ListNormTables::class)
            ->callTableAction('importCsv', $this->normTable, data: ['csv' => $file])
            ->assertNotified('Import fehlgeschlagen — keine Zeilen importiert');

        $this->assertSame([], $this->storedRows());
        $this->assertSame([], Storage::disk('local')->allFiles('lsp/imports'));
    }

    #[Test]
    public function csv_without_data_rows_shows_error(): void
    {
        $file = UploadedFile::fake()->createWithContent('normen.csv', "raw_score;quotient_male;quotient_female\n");

        Livewire::test(ListNormTables::class)
            ->callTableAction('importCsv', $this->normTable, data: ['csv' => $file])
            ->assertNotified('Import fehlgeschlagen — keine Zeilen importiert');

        $this->assertSame([], $this->storedRows());
    }
}
