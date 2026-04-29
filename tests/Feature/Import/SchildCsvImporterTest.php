<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Crypto\CryptoService;
use App\Domain\Import\Adapters\SchildCsvImporter;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\Models\ImportDiffEntry;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchildCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    private SchildCsvImporter $importer;
    private CryptoService $crypto;
    private User $admin;
    private SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DefaultUserGroupsSeeder::class);

        $this->crypto = app(CryptoService::class);
        $this->admin = User::create([
            'username' => 'admin',
            'display_name' => 'Admin',
            'password' => Hash::make('admin-pass-1234567890'),
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->crypto->initialize($this->admin, 'clear-pass-1234567890');

        $this->schoolYear = SchoolYear::create([
            'label' => '2026/27',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_active' => true,
        ]);

        $this->importer = app(SchildCsvImporter::class);
    }

    private function makeCsv(array $rows, bool $withHeader = true): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $h = fopen($tmp, 'w');
        if ($withHeader) {
            fputcsv($h, ['ID', 'Name', 'Vorname', 'Klasse', 'Geschlecht'], ';', '"', '');
        }
        foreach ($rows as $r) {
            fputcsv($h, $r, ';', '"', '');
        }
        fclose($h);

        return $tmp;
    }

    #[Test]
    public function it_validates_csv_and_marks_invalid_rows(): void
    {
        $csv = $this->makeCsv([
            ['1234', 'Müller', 'Anna', '5a', 'w'],
            ['', '', 'Max', '5a', 'm'],          // ungültig: kein Nachname, keine ID
        ]);

        $result = $this->importer->validate(new ImportInput($csv, 'test.csv'));

        $this->assertEquals(2, $result->totalRows);
        $this->assertEquals(1, $result->validRows);
        $this->assertEquals(1, $result->errorRows);
    }

    #[Test]
    public function diff_creates_new_students_for_unknown_external_ids(): void
    {
        $csv = $this->makeCsv([
            ['1001', 'Müller', 'Anna', '5a', 'w'],
            ['1002', 'Schmidt', 'Ben', '5a', 'm'],
        ]);

        $diff = $this->importer->diff(new ImportInput($csv, 'csv.csv'), $this->schoolYear->id, 'klasse');

        $this->assertEquals(2, $diff->createCount);
        $this->assertEquals(0, $diff->updateCount);
        $this->assertEquals(0, $diff->archiveCount);
    }

    #[Test]
    public function diff_detects_existing_students_as_update_or_skip(): void
    {
        // Schüler vorab anlegen via direktem create (mit unlocked DEK)
        $student = new Student;
        $student->external_student_id = '2001';
        $student->external_id_source = 'schild';
        $student->student_code = Student::generateUniqueCode('LSP');
        $student->first_name_encrypted = 'Anna';
        $student->last_name_encrypted = 'Müller';
        $student->gender = 'w';
        $student->save();
        $student->enrollments()->create([
            'school_year_id' => $this->schoolYear->id,
            'enrolled_at' => now()->toDateString(),
        ]);
        $group = LearningGroup::create([
            'school_year_id' => $this->schoolYear->id,
            'name' => '5a',
            'group_type' => 'klasse',
        ]);
        $student->memberships()->create([
            'learning_group_id' => $group->id,
            'school_year_id' => $this->schoolYear->id,
        ]);

        // Re-Import: gleiche Daten → skip
        $csv = $this->makeCsv([
            ['2001', 'Müller', 'Anna', '5a', 'w'],
        ]);
        $diff = $this->importer->diff(new ImportInput($csv, 'csv.csv'), $this->schoolYear->id, 'klasse');
        $this->assertEquals(1, $diff->skipCount);
        $this->assertEquals(0, $diff->updateCount);

        // Klassenwechsel → update
        $csv2 = $this->makeCsv([
            ['2001', 'Müller', 'Anna', '5b', 'w'],
        ]);
        $diff2 = $this->importer->diff(new ImportInput($csv2, 'csv2.csv'), $this->schoolYear->id, 'klasse');
        $this->assertEquals(1, $diff2->updateCount);
    }

    #[Test]
    public function diff_marks_missing_students_as_archive_candidates(): void
    {
        // Bestehender Schüler im Schuljahr
        $student = new Student;
        $student->external_student_id = '3001';
        $student->external_id_source = 'schild';
        $student->student_code = Student::generateUniqueCode();
        $student->first_name_encrypted = 'Lara';
        $student->last_name_encrypted = 'Becker';
        $student->gender = 'w';
        $student->save();
        $student->enrollments()->create([
            'school_year_id' => $this->schoolYear->id,
            'enrolled_at' => now()->toDateString(),
        ]);

        // Import enthält ihn nicht
        $csv = $this->makeCsv([
            ['9999', 'Neu', 'Person', '5a', 'm'],
        ]);
        $diff = $this->importer->diff(new ImportInput($csv, 'csv.csv'), $this->schoolYear->id, 'klasse');

        $this->assertEquals(1, $diff->createCount);
        $this->assertEquals(1, $diff->archiveCount);
    }

    #[Test]
    public function commit_creates_updates_archives_correctly(): void
    {
        // Bestand: 1 Schüler, der archiviert werden soll
        $oldStudent = new Student;
        $oldStudent->external_student_id = '4001';
        $oldStudent->external_id_source = 'schild';
        $oldStudent->student_code = Student::generateUniqueCode();
        $oldStudent->first_name_encrypted = 'Old';
        $oldStudent->last_name_encrypted = 'Student';
        $oldStudent->gender = 'm';
        $oldStudent->save();
        $oldStudent->enrollments()->create([
            'school_year_id' => $this->schoolYear->id,
            'enrolled_at' => now()->toDateString(),
        ]);

        // Import: 1 neuer Schüler
        $csv = $this->makeCsv([
            ['5001', 'Neu', 'Lisa', '5a', 'w'],
        ]);
        $diff = $this->importer->diff(new ImportInput($csv, 'csv.csv'), $this->schoolYear->id, 'klasse');

        // Commit: alle bestätigen
        $entries = ImportDiffEntry::where('import_job_id', $diff->importJobId)->get();
        $decisions = [];
        foreach ($entries as $e) {
            $decisions[$e->id] = 'confirm';
        }
        $result = $this->importer->commit($diff->importJobId, $decisions);

        $this->assertEquals(1, $result->imported);
        $this->assertEquals(1, $result->archived);

        // Verifikation
        $oldStudent->refresh();
        $this->assertEquals('archiviert', $oldStudent->status);

        $newStudent = Student::where('external_student_id', '5001')->first();
        $this->assertNotNull($newStudent);
        $this->assertEquals('Lisa', $newStudent->first_name_encrypted);
        $this->assertTrue($newStudent->learningGroups()->where('name', '5a')->exists());
    }

    #[Test]
    public function commit_excludes_entries_with_decision_exclude(): void
    {
        // Bestand: 1 Schüler
        $oldStudent = new Student;
        $oldStudent->external_student_id = '4001';
        $oldStudent->external_id_source = 'schild';
        $oldStudent->student_code = Student::generateUniqueCode();
        $oldStudent->first_name_encrypted = 'Soll';
        $oldStudent->last_name_encrypted = 'Bleiben';
        $oldStudent->gender = 'm';
        $oldStudent->save();
        $oldStudent->enrollments()->create([
            'school_year_id' => $this->schoolYear->id,
            'enrolled_at' => now()->toDateString(),
        ]);

        $csv = $this->makeCsv([
            ['5001', 'Neu', 'Lisa', '5a', 'w'],
        ]);
        $diff = $this->importer->diff(new ImportInput($csv, 'csv.csv'), $this->schoolYear->id, 'klasse');

        // Archivkandidat ausschließen
        $entries = ImportDiffEntry::where('import_job_id', $diff->importJobId)->get();
        $decisions = [];
        foreach ($entries as $e) {
            $decisions[$e->id] = $e->action === 'archive' ? 'exclude' : 'confirm';
        }
        $result = $this->importer->commit($diff->importJobId, $decisions);

        $this->assertEquals(0, $result->archived);
        $oldStudent->refresh();
        $this->assertEquals('aktiv', $oldStudent->status);
    }

    #[Test]
    public function commit_requires_clearname_unlock(): void
    {
        $csv = $this->makeCsv([['1', 'A', 'B', '5a', 'm']]);
        $diff = $this->importer->diff(new ImportInput($csv, 'csv.csv'), $this->schoolYear->id, 'klasse');

        $this->crypto->lock();

        $this->expectException(\RuntimeException::class);
        $this->importer->commit($diff->importJobId, []);
    }
}
