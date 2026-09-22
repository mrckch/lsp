<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Crypto\CryptoService;
use App\Domain\Import\Adapters\SchildCsvImporter;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\Models\ImportJob;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchildCsvImporterChunkTest extends TestCase
{
    use RefreshDatabase;

    private SchildCsvImporter $importer;

    private SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DefaultUserGroupsSeeder::class);

        $crypto = app(CryptoService::class);
        $admin = User::create([
            'username' => 'admin', 'display_name' => 'Admin',
            'password' => Hash::make('admin-pass-1234567890'), 'is_active' => true,
        ]);
        $this->actingAs($admin);
        $crypto->initialize($admin, 'clear-pass-1234567890');

        $this->schoolYear = SchoolYear::create([
            'label' => '2026/27', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31', 'is_active' => true,
        ]);

        $this->importer = app(SchildCsvImporter::class);
    }

    private function makeCsv(int $count): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $h = fopen($tmp, 'w');
        fputcsv($h, ['ID', 'Name', 'Vorname', 'Klasse', 'Geschlecht'], ';', '"', '');
        for ($i = 1; $i <= $count; $i++) {
            fputcsv($h, [(string) (1000 + $i), 'Nach'.$i, 'Vor'.$i, '5a', $i % 2 ? 'w' : 'm'], ';', '"', '');
        }
        fclose($h);

        return $tmp;
    }

    private function makeDiffJobId(int $count): int
    {
        $input = new ImportInput(filePath: $this->makeCsv($count), filename: 'test.csv');

        return $this->importer->diff($input, $this->schoolYear->id, 'klasse')->importJobId;
    }

    #[Test]
    public function commit_chunk_processes_in_batches_and_reports_progress(): void
    {
        $jobId = $this->makeDiffJobId(5);

        $first = $this->importer->commitChunk($jobId, 2);
        $this->assertFalse($first['done']);
        $this->assertEquals(2, $first['processed']);
        $this->assertEquals(5, $first['total']);
        $this->assertEquals('committing', ImportJob::find($jobId)->status);

        $this->importer->commitChunk($jobId, 2); // processed 4

        $last = $this->importer->commitChunk($jobId, 2); // processed 5, done
        $this->assertTrue($last['done']);
        $this->assertEquals(5, $last['processed']);
        $this->assertEquals(5, $last['counts']['imported']);

        $this->assertEquals(5, Student::count());
        $this->assertEquals('committed', ImportJob::find($jobId)->status);
    }

    #[Test]
    public function commit_chunk_is_idempotent_after_completion(): void
    {
        $jobId = $this->makeDiffJobId(3);

        do {
            $progress = $this->importer->commitChunk($jobId, 10);
        } while (! $progress['done']);

        $this->assertEquals(3, Student::count());

        // Erneuter Aufruf nach Abschluss darf keine Duplikate anlegen.
        $again = $this->importer->commitChunk($jobId, 10);
        $this->assertTrue($again['done']);
        $this->assertEquals(3, Student::count());
    }
}
