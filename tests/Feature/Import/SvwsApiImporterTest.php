<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Crypto\CryptoService;
use App\Domain\Import\Adapters\SchildCsvImporter;
use App\Domain\Import\Adapters\SvwsApiImporter;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\ImporterFactory;
use App\Domain\Import\Models\ImportDiffEntry;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\SvwsApiClient;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\Student\Models\StudentGroupMembership;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SvwsApiImporterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolYear $year;

    private ImportSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        AppSetting::singleton()->update(['is_initialized' => true, 'school_short_name' => 'TEST']);

        $this->admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'clear-pw-1234567890');
        $this->actingAs($this->admin);

        $this->year = SchoolYear::create([
            'label' => '2026/27',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
        ]);

        $this->source = ImportSource::create([
            'key' => 'svws_main',
            'name' => 'Test-SVWS',
            'type' => 'svws_api',
            'is_active' => true,
            'config_encrypted' => [
                'api_url' => 'https://svws.test',
                'schema' => 'svwsdb',
                'username' => 'hoett',
                'password' => 'secret',
                'verify_ssl' => false,
                'timeout_seconds' => 20,
            ],
        ]);
    }

    private function fakeApi(): void
    {
        $school = json_decode((string) file_get_contents(base_path('tests/Fixtures/svws/schule_stammdaten.json')), true);
        $students = json_decode((string) file_get_contents(base_path('tests/Fixtures/svws/schueler_abschnitt_1.json')), true);
        $classes = json_decode((string) file_get_contents(base_path('tests/Fixtures/svws/klassen_abschnitt_1.json')), true);

        Http::fake([
            'svws.test/db/svwsdb/schule/stammdaten' => Http::response($school, 200),
            'svws.test/db/svwsdb/schueler/abschnitt/*' => Http::response($students, 200),
            'svws.test/db/svwsdb/klassen/abschnitt/*' => Http::response($classes, 200),
        ]);
    }

    private function input(): ImportInput
    {
        return new ImportInput(
            filePath: '',
            filename: 'svws_api',
            sourceId: $this->source->id,
        );
    }

    #[Test]
    public function client_calls_correct_endpoints_with_basic_auth(): void
    {
        $this->fakeApi();
        $client = new SvwsApiClient($this->source);

        $client->fetchSchoolInfo();
        $client->fetchStudents(1);
        $client->fetchClasses(1);

        Http::assertSentCount(3);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/db/svwsdb/schule/stammdaten')
            && $req->hasHeader('Authorization')
            && str_starts_with((string) $req->header('Authorization')[0], 'Basic '));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/db/svwsdb/schueler/abschnitt/1'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/db/svwsdb/klassen/abschnitt/1'));
    }

    #[Test]
    public function validate_normalizes_real_response_shape(): void
    {
        $this->fakeApi();
        $importer = app(SvwsApiImporter::class);

        $result = $importer->validate($this->input());

        // 3 Schüler in den Fixtures, alle status=2 (aktiv)
        $this->assertEquals(3, $result->totalRows);
        $this->assertEquals(3, $result->validRows);
        $this->assertEquals(0, $result->errorRows);

        $first = $result->rows[0];
        $this->assertEquals('3213', $first['external_student_id']);
        $this->assertEquals('Mustermann', $first['last_name']);
        $this->assertEquals('Max', $first['first_name']);
        $this->assertEquals('m', $first['gender']);
        $this->assertEquals('05a', $first['group_name']); // idKlasse=1 → 05a aus Klassen-Fixture
    }

    #[Test]
    public function diff_creates_new_students_with_svws_external_id_source(): void
    {
        $this->fakeApi();
        $importer = app(SvwsApiImporter::class);

        $diff = $importer->diff($this->input(), $this->year->id, 'klasse');

        $this->assertEquals(3, $diff->createCount);
        $this->assertEquals(0, $diff->updateCount);
        $this->assertEquals(0, $diff->archiveCount);
        $this->assertEquals(0, $diff->errorCount);
    }

    #[Test]
    public function commit_persists_students_classes_and_memberships(): void
    {
        $this->fakeApi();
        $importer = app(SvwsApiImporter::class);

        $diff = $importer->diff($this->input(), $this->year->id, 'klasse');

        // Alle Diff-Einträge bestätigen
        $entries = ImportDiffEntry::query()
            ->where('import_job_id', $diff->importJobId)->get();
        $decisions = [];
        foreach ($entries as $e) {
            $decisions[$e->id] = 'confirm';
        }

        $result = $importer->commit($diff->importJobId, $decisions);

        $this->assertEquals(3, $result->imported);
        $this->assertEquals(0, $result->failed);

        $this->assertEquals(3, Student::query()->where('external_id_source', 'svws')->count());
        // 2 Klassen erzeugt: 05a (2 SuS), 05b (1 SuS) — vom 3. Schüler in Klasse 2
        $this->assertEquals(2, LearningGroup::query()->count());
        $this->assertEquals(3, StudentGroupMembership::query()->count());
    }

    #[Test]
    public function diff_marks_existing_student_as_archive_when_missing_in_import(): void
    {
        $this->fakeApi();

        // Vorhandener SVWS-Schüler, der NICHT mehr im Import ist
        $orphan = new Student;
        $orphan->external_student_id = '9999';
        $orphan->external_id_source = 'svws';
        $orphan->student_code = Student::generateUniqueCode('TEST');
        $orphan->first_name_encrypted = 'Alt';
        $orphan->last_name_encrypted = 'Schüler';
        $orphan->gender = 'm';
        $orphan->status = 'aktiv';
        $orphan->save();
        $orphan->enrollments()->create([
            'school_year_id' => $this->year->id,
            'enrolled_at' => now()->toDateString(),
        ]);

        $importer = app(SvwsApiImporter::class);
        $diff = $importer->diff($this->input(), $this->year->id, 'klasse');

        $this->assertEquals(3, $diff->createCount);
        $this->assertEquals(1, $diff->archiveCount);
    }

    #[Test]
    public function diff_skips_unchanged_existing_student(): void
    {
        $this->fakeApi();

        // Schüler existiert bereits exakt wie in Import
        $existing = new Student;
        $existing->external_student_id = '3213';
        $existing->external_id_source = 'svws';
        $existing->student_code = Student::generateUniqueCode('TEST');
        $existing->first_name_encrypted = 'Max';
        $existing->last_name_encrypted = 'Mustermann';
        $existing->gender = 'm';
        $existing->status = 'aktiv';
        $existing->save();
        $existing->enrollments()->create([
            'school_year_id' => $this->year->id,
            'enrolled_at' => now()->toDateString(),
        ]);
        // Klassenzugehörigkeit muss zur Import-Klasse passen, sonst wäre es 'update'
        $group = LearningGroup::create([
            'school_year_id' => $this->year->id,
            'name' => '05a', 'group_type' => 'klasse', 'is_active' => true,
        ]);
        $existing->memberships()->create([
            'learning_group_id' => $group->id,
            'school_year_id' => $this->year->id,
        ]);

        $importer = app(SvwsApiImporter::class);
        $diff = $importer->diff($this->input(), $this->year->id, 'klasse');

        $this->assertEquals(2, $diff->createCount);
        $this->assertEquals(1, $diff->skipCount); // Mustermann unverändert
    }

    #[Test]
    public function diff_marks_changed_existing_student_as_update(): void
    {
        $this->fakeApi();

        $existing = new Student;
        $existing->external_student_id = '3213';
        $existing->external_id_source = 'svws';
        $existing->student_code = Student::generateUniqueCode('TEST');
        $existing->first_name_encrypted = 'Maximilian'; // Klartext-Differenz
        $existing->last_name_encrypted = 'Mustermann';
        $existing->gender = 'm';
        $existing->status = 'aktiv';
        $existing->save();
        $existing->enrollments()->create([
            'school_year_id' => $this->year->id,
            'enrolled_at' => now()->toDateString(),
        ]);

        $importer = app(SvwsApiImporter::class);
        $diff = $importer->diff($this->input(), $this->year->id, 'klasse');

        $this->assertEquals(1, $diff->updateCount);
    }

    #[Test]
    public function api_5xx_error_throws_runtime_exception(): void
    {
        Http::fake([
            'svws.test/db/svwsdb/schule/stammdaten' => Http::response('boom', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SVWS-API-Aufruf fehlgeschlagen');

        app(SvwsApiImporter::class)->validate($this->input());
    }

    #[Test]
    public function inactive_source_is_rejected(): void
    {
        $this->source->update(['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nicht aktiv');

        app(SvwsApiImporter::class)->validate($this->input());
    }

    #[Test]
    public function grade_filter_excludes_students_outside_filter(): void
    {
        // Custom-Fixture: einen Schüler in jahrgang '11' hinzufügen
        Http::fake([
            'svws.test/db/svwsdb/schule/stammdaten' => Http::response(
                json_decode(file_get_contents(base_path('tests/Fixtures/svws/schule_stammdaten.json')), true),
                200,
            ),
            'svws.test/db/svwsdb/schueler/abschnitt/*' => Http::response([
                ['id' => 100, 'nachname' => 'Sek1', 'vorname' => 'A', 'geschlecht' => 'm',
                    'idKlasse' => 1, 'jahrgang' => '05', 'status' => 2, 'idSchuljahresabschnitt' => 1],
                ['id' => 101, 'nachname' => 'Sek2', 'vorname' => 'B', 'geschlecht' => 'w',
                    'idKlasse' => 1, 'jahrgang' => '11', 'status' => 2, 'idSchuljahresabschnitt' => 1],
            ], 200),
            'svws.test/db/svwsdb/klassen/abschnitt/*' => Http::response(
                json_decode(file_get_contents(base_path('tests/Fixtures/svws/klassen_abschnitt_1.json')), true),
                200,
            ),
        ]);

        $importer = app(SvwsApiImporter::class);
        $input = new ImportInput(
            filePath: '', filename: 'svws_api',
            sourceId: $this->source->id,
            gradeFilter: ImportInput::SEK_I_GRADES,
        );

        $result = $importer->validate($input);

        // Nur SekI-Schüler ist enthalten
        $this->assertEquals(1, $result->totalRows);
        $this->assertEquals('05', $result->rows[0]['jahrgang']);
    }

    #[Test]
    public function grade_filter_does_not_archive_students_outside_filter(): void
    {
        $this->fakeApi();

        // Bestehender SekII-Schüler (jahrgang 11) — muss vom SekI-Import unangetastet bleiben
        $sekIIStudent = new Student;
        $sekIIStudent->external_student_id = '5555';
        $sekIIStudent->external_id_source = 'svws';
        $sekIIStudent->student_code = Student::generateUniqueCode('TEST');
        $sekIIStudent->first_name_encrypted = 'SekII';
        $sekIIStudent->last_name_encrypted = 'Bleibt';
        $sekIIStudent->gender = 'm';
        $sekIIStudent->status = 'aktiv';
        $sekIIStudent->save();
        $sekIIStudent->enrollments()->create([
            'school_year_id' => $this->year->id,
            'grade_level' => '11',
            'enrolled_at' => now()->toDateString(),
        ]);

        $importer = app(SvwsApiImporter::class);
        $input = new ImportInput(
            filePath: '', filename: 'svws_api',
            sourceId: $this->source->id,
            gradeFilter: ImportInput::SEK_I_GRADES,
        );

        $diff = $importer->diff($input, $this->year->id, 'klasse');

        // Keine Archivkandidaten (SekII bleibt unangetastet)
        $this->assertEquals(0, $diff->archiveCount);
    }

    #[Test]
    public function importer_factory_returns_correct_adapter(): void
    {
        $factory = app(ImporterFactory::class);

        $this->assertInstanceOf(SchildCsvImporter::class, $factory->make('schild_csv'));
        $this->assertInstanceOf(SvwsApiImporter::class, $factory->make('svws_api'));

        $this->expectException(\InvalidArgumentException::class);
        $factory->make('unbekannt');
    }
}
