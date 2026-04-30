<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Mail\MailService;
use App\Domain\PrintJob\BulkFeedbackGenerator;
use App\Domain\TestRun\Models\TestRun;
use App\Jobs\Concerns\LogsFailureToAudit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Erzeugt asynchron das Rückmeldungs-ZIP eines TestRuns und versendet es
 * per Mail mit ZIP-Anhang an einen einzigen Empfänger
 * (z. B. Klassenlehrkraft).
 */
class SendBulkFeedbackMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LogsFailureToAudit;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    protected function failureContext(): array
    {
        return ['test_run_id' => $this->testRunId, 'recipient' => $this->recipient, 'kind' => 'bulk_feedback_mail'];
    }

    public function __construct(
        public readonly int $testRunId,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly ?int $userId,
    ) {
        $this->onQueue('mail');
    }

    public function handle(BulkFeedbackGenerator $generator, MailService $mail): void
    {
        $run = TestRun::query()->findOrFail($this->testRunId);
        $user = $this->userId ? User::find($this->userId) : null;

        $result = $generator->generateForRun($run, forUser: $user);
        if ($result['count'] === 0) {
            return;
        }

        $bytes = file_get_contents($result['zip']);
        @unlink($result['zip']);

        $mail->sendWithRawAttachment(
            to: [$this->recipient],
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            attachmentName: 'rueckmeldungen_'.$run->short_code.'.zip',
            attachmentMime: 'application/zip',
            attachmentBytes: $bytes,
            includesClearnames: true,
            userId: $this->userId,
        );
    }
}
