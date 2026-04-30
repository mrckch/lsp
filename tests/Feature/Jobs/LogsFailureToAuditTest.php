<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Audit\Models\AuditLog;
use App\Filament\Widgets\MyJobsStatus;
use App\Jobs\GenerateBulkFeedbackZipJob;
use App\Jobs\GenerateBulkHistoryZipJob;
use App\Jobs\SendBulkFeedbackMailJob;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LogsFailureToAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);
        $this->user = User::create([
            'username' => 'u', 'display_name' => 'U',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);
    }

    #[Test]
    public function failed_bulk_feedback_zip_writes_audit_with_context(): void
    {
        $job = new GenerateBulkFeedbackZipJob(testRunId: 42, userId: $this->user->id);
        $job->failed(new \RuntimeException('Gotenberg down'));

        $audit = AuditLog::query()->where('action', 'job.failed')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('user', $audit->actor_type);
        $this->assertEquals($this->user->id, $audit->actor_user_id);
        $this->assertEquals('bulk_feedback_zip', $audit->context['kind']);
        $this->assertEquals(42, $audit->context['test_run_id']);
        $this->assertStringContainsString('Gotenberg down', $audit->context['error']);
        $this->assertEquals('RuntimeException', $audit->context['exception']);
    }

    #[Test]
    public function failed_bulk_history_zip_includes_student_count(): void
    {
        $job = new GenerateBulkHistoryZipJob(studentIds: [1, 2, 3, 4], userId: $this->user->id);
        $job->failed(new \RuntimeException('boom'));

        $audit = AuditLog::query()->where('action', 'job.failed')->first();
        $this->assertEquals('bulk_history_zip', $audit->context['kind']);
        $this->assertEquals(4, $audit->context['student_count']);
    }

    #[Test]
    public function failed_bulk_mail_includes_recipient(): void
    {
        $job = new SendBulkFeedbackMailJob(
            testRunId: 7, recipient: 'lehrer@example.com',
            subject: 'Test', bodyHtml: '<p>X</p>', userId: $this->user->id,
        );
        $job->failed(new \RuntimeException('SMTP timeout'));

        $audit = AuditLog::query()->where('action', 'job.failed')->first();
        $this->assertEquals('bulk_feedback_mail', $audit->context['kind']);
        $this->assertEquals('lehrer@example.com', $audit->context['recipient']);
        $this->assertEquals(7, $audit->context['test_run_id']);
    }

    #[Test]
    public function failure_appears_in_my_jobs_status_widget(): void
    {
        $job = new GenerateBulkFeedbackZipJob(testRunId: 99, userId: $this->user->id);
        $job->failed(new \RuntimeException('Test-Fehler'));

        $this->actingAs($this->user);
        $widget = new MyJobsStatus;
        $rows = $widget->getActivities();

        $this->assertCount(1, $rows);
        $this->assertEquals('failure', $rows->first()['kind']);
        $this->assertEquals('Bulk-Rückmeldungs-ZIP', $rows->first()['title']);
        $this->assertStringContainsString('Test-Fehler', $rows->first()['subtitle']);
        $this->assertEquals('fehlgeschlagen', $rows->first()['status']);
    }

    #[Test]
    public function failure_only_visible_to_actor_user(): void
    {
        $other = User::create([
            'username' => 'o', 'display_name' => 'O',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);

        $job = new GenerateBulkFeedbackZipJob(testRunId: 99, userId: $this->user->id);
        $job->failed(new \RuntimeException('private'));

        $this->actingAs($other);
        $rows = (new MyJobsStatus)->getActivities();
        $this->assertCount(0, $rows);
    }
}
