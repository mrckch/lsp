<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\PrintJob\BulkHistoryExporter;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Jobs\GenerateBulkHistoryZipJob;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateBulkHistoryZipJobTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $studentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        AppSetting::singleton()->update(['school_name' => 'BSP']);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        $run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);

        $tpl = PrintTemplate::create([
            'key' => 'verlaufsdiagramm', 'name' => 'Verlauf',
            'type' => 'student_history', 'is_system' => true,
        ]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => '<h1>{{student_name}}</h1>',
            'created_by_user_id' => $this->admin->id,
        ]);
        $tpl->update(['current_version_id' => $version->id]);

        foreach (['A', 'B', 'C'] as $name) {
            $s = new Student;
            $s->external_student_id = $name;
            $s->external_id_source = 'manual';
            $s->student_code = Student::generateUniqueCode();
            $s->first_name_encrypted = $name;
            $s->last_name_encrypted = $name;
            $s->gender = 'w';
            $s->save();
            $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $run->id,
                'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                'started_at' => now(), 'submitted_at' => now(),
                'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => 100, 'lq_current' => 100,
            ]);
            $this->studentIds[] = $s->id;
        }

        $this->app->bind(GotenbergClient::class, fn () => new class('http://x') extends GotenbergClient
        {
            public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
            {
                return "%PDF\n".$html;
            }
        });
    }

    #[Test]
    public function dispatch_pushes_job_to_pdf_queue(): void
    {
        Bus::fake();
        GenerateBulkHistoryZipJob::dispatch($this->studentIds, $this->admin->id);

        Bus::assertDispatched(GenerateBulkHistoryZipJob::class, function ($job) {
            return $job->studentIds === $this->studentIds
                && $job->userId === $this->admin->id
                && $job->queue === 'pdf';
        });
    }

    #[Test]
    public function handle_creates_generated_document_with_zip(): void
    {
        $this->assertEquals(0, GeneratedDocument::count());

        $job = new GenerateBulkHistoryZipJob($this->studentIds, $this->admin->id);
        $job->handle(app(BulkHistoryExporter::class));

        $this->assertEquals(1, GeneratedDocument::count());
        $doc = GeneratedDocument::first();
        $this->assertEquals('application/zip', $doc->mime_type);
        $this->assertTrue($doc->includes_clearnames);
        $this->assertGreaterThan(0, $doc->size_bytes);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    #[Test]
    public function handle_creates_no_document_when_all_students_have_no_attempts(): void
    {
        TestAttempt::query()->delete();

        $job = new GenerateBulkHistoryZipJob($this->studentIds, $this->admin->id);
        $job->handle(app(BulkHistoryExporter::class));

        $this->assertEquals(0, GeneratedDocument::count());
    }
}
