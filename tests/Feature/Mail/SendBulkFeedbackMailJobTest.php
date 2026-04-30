<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Mail\MailService;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\PrintJob\BulkFeedbackGenerator;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Jobs\SendBulkFeedbackMailJob;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendBulkFeedbackMailJobTest extends TestCase
{
    use RefreshDatabase;

    private TestRun $run;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
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

        $tpl = PrintTemplate::create([
            'key' => 'rueckmeldung', 'name' => 'R', 'type' => 'student_feedback', 'is_system' => true,
        ]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => '<h1>{{student_name}}</h1>',
            'created_by_user_id' => $this->admin->id,
        ]);
        $tpl->update(['current_version_id' => $version->id]);

        $this->run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run->learningGroups()->attach($g->id);

        $s = new Student;
        $s->external_student_id = 'X';
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = 'Anna';
        $s->last_name_encrypted = 'Müller';
        $s->gender = 'w';
        $s->save();
        $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
        TestAttempt::create([
            'student_id' => $s->id, 'test_run_id' => $this->run->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now(), 'submitted_at' => now(),
            'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => 100, 'lq_current' => 100,
        ]);

        $this->app->bind(GotenbergClient::class, fn () => new class('http://x') extends GotenbergClient
        {
            public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
            {
                return "%PDF\n".$html;
            }
        });
    }

    #[Test]
    public function dispatch_pushes_to_mail_queue(): void
    {
        Bus::fake();

        SendBulkFeedbackMailJob::dispatch(
            testRunId: $this->run->id,
            recipient: 'lehrer@example.com',
            subject: 'Test',
            bodyHtml: '<p>Hi</p>',
            userId: $this->admin->id,
        );

        Bus::assertDispatched(SendBulkFeedbackMailJob::class, function ($job) {
            return $job->testRunId === $this->run->id
                && $job->recipient === 'lehrer@example.com'
                && $job->queue === 'mail';
        });
    }

    #[Test]
    public function handle_creates_mail_message_with_attachment_and_clearnames_flag(): void
    {
        Mail::fake();

        $job = new SendBulkFeedbackMailJob(
            testRunId: $this->run->id,
            recipient: 'lehrer@example.com',
            subject: 'Rückmeldungen',
            bodyHtml: '<p>Anbei.</p>',
            userId: $this->admin->id,
        );
        $job->handle(app(BulkFeedbackGenerator::class), app(MailService::class));

        $this->assertEquals(1, MailMessage::count());
        $msg = MailMessage::first();
        $this->assertEquals('sent', $msg->status);
        $this->assertTrue($msg->includes_clearnames);
        $this->assertEquals(1, $msg->attachments()->count());
        $att = $msg->attachments()->first();
        $this->assertStringContainsString('rueckmeldungen_', $att->file_name);
        $this->assertEquals('application/zip', $att->mime_type);
        $this->assertGreaterThan(0, $att->size_bytes);
    }

    #[Test]
    public function handle_does_nothing_when_no_attempts(): void
    {
        Mail::fake();
        TestAttempt::query()->delete();

        $job = new SendBulkFeedbackMailJob(
            testRunId: $this->run->id,
            recipient: 'lehrer@example.com',
            subject: 'X', bodyHtml: 'x', userId: $this->admin->id,
        );
        $job->handle(app(BulkFeedbackGenerator::class), app(MailService::class));

        $this->assertEquals(0, MailMessage::count());
    }
}
