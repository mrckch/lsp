<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Mail\MailService;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\SupportThreshold\Models\SupportThreshold;
use App\Domain\SupportThreshold\ThresholdEvaluator;
use App\Domain\TestRun\Models\TestRun;
use App\Jobs\MailSupportListJob;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailSupportListJobTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolYear $sy;

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

        $this->sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse', 'grade_level' => '5']);
        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        $run = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);

        $tpl = PrintTemplate::create([
            'key' => 'foerderbedarfsliste', 'name' => 'Liste',
            'type' => 'support_list', 'is_system' => true,
        ]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => '<h1>Förderbedarf</h1>', 'created_by_user_id' => $this->admin->id,
        ]);
        $tpl->update(['current_version_id' => $version->id]);

        SupportThreshold::create([
            'name' => 'LQ < 70', 'metric' => 'lq_absolute', 'operator' => 'lt',
            'value' => 70, 'severity' => 'foerderbedarf', 'is_active' => true,
        ]);

        // Schüler mit LQ 60 → Treffer
        $s = new Student;
        $s->external_student_id = 'X';
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = 'Anna';
        $s->last_name_encrypted = 'Müller';
        $s->gender = 'w';
        $s->save();
        $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $this->sy->id]);
        $s->enrollments()->create(['school_year_id' => $this->sy->id, 'enrolled_at' => now()->toDateString()]);
        TestAttempt::create([
            'student_id' => $s->id, 'test_run_id' => $run->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now(), 'submitted_at' => now(),
            'time_limit_seconds' => 180, 'score_raw' => 30, 'lq_at_submission' => 60, 'lq_current' => 60,
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
        MailSupportListJob::dispatch(
            filters: ['severity' => 'all'],
            recipient: 'koord@example.com',
            subject: 'Liste',
            bodyHtml: '<p>Hi</p>',
            userId: $this->admin->id,
        );

        Bus::assertDispatched(MailSupportListJob::class, function ($job) {
            return $job->recipient === 'koord@example.com'
                && $job->queue === 'mail';
        });
    }

    #[Test]
    public function handle_creates_mail_with_pdf_attachment(): void
    {
        Mail::fake();

        $job = new MailSupportListJob(
            filters: ['severity' => 'all'],
            recipient: 'koord@example.com',
            subject: 'Förderbedarf',
            bodyHtml: '<p>Anbei die Liste.</p>',
            userId: $this->admin->id,
        );
        $job->handle(app(MailService::class), app(ThresholdEvaluator::class));

        $msg = MailMessage::first();
        $this->assertNotNull($msg);
        $this->assertEquals('sent', $msg->status);
        $this->assertTrue($msg->includes_clearnames);
        $att = $msg->attachments()->first();
        $this->assertEquals('application/pdf', $att->mime_type);
        $this->assertStringStartsWith('foerderbedarf_', $att->file_name);
    }

    #[Test]
    public function handle_does_nothing_when_no_rows(): void
    {
        Mail::fake();
        SupportThreshold::query()->delete();

        $job = new MailSupportListJob(
            filters: ['severity' => 'all'],
            recipient: 'koord@example.com',
            subject: 'X', bodyHtml: 'x', userId: $this->admin->id,
        );
        $job->handle(app(MailService::class), app(ThresholdEvaluator::class));

        $this->assertEquals(0, MailMessage::count());
    }

    #[Test]
    public function handle_throws_when_template_missing(): void
    {
        PrintTemplate::query()->where('key', 'foerderbedarfsliste')->delete();

        $job = new MailSupportListJob(
            filters: ['severity' => 'all'],
            recipient: 'koord@example.com',
            subject: 'X', bodyHtml: 'x', userId: $this->admin->id,
        );

        $this->expectException(\RuntimeException::class);
        $job->handle(app(MailService::class), app(ThresholdEvaluator::class));
    }
}
