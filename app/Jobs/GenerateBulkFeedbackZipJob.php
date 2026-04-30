<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\PrintJob\BulkFeedbackGenerator;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Domain\TestRun\Models\TestRun;
use App\Jobs\Concerns\LogsFailureToAudit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Erzeugt asynchron ein Rückmeldungs-ZIP für einen TestRun
 * und persistiert es als GeneratedDocument zum Download.
 */
class GenerateBulkFeedbackZipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LogsFailureToAudit;
    use Queueable;
    use SerializesModels;

    protected function failureContext(): array
    {
        return ['test_run_id' => $this->testRunId, 'kind' => 'bulk_feedback_zip'];
    }

    public int $timeout = 600; // 10 min

    public function __construct(
        public readonly int $testRunId,
        public readonly ?int $userId,
    ) {
        $this->onQueue('pdf');
    }

    public function handle(BulkFeedbackGenerator $generator): void
    {
        $run = TestRun::query()->findOrFail($this->testRunId);
        $user = $this->userId ? User::find($this->userId) : null;

        $result = $generator->generateForRun($run, forUser: $user);

        if ($result['count'] === 0) {
            return;
        }

        $bytes = file_get_contents($result['zip']);
        @unlink($result['zip']);

        $filename = 'rueckmeldungen_'.$run->short_code.'_'.now()->format('Ymd_His').'.zip';
        $path = 'lsp/print-jobs/'.$filename;
        Storage::disk('local')->put($path, $bytes);

        GeneratedDocument::create([
            'file_name' => $filename,
            'file_path' => $path,
            'mime_type' => 'application/zip',
            'size_bytes' => strlen($bytes),
            'includes_clearnames' => true,
            'sha256' => hash('sha256', $bytes),
            'expires_at' => now()->addDays((int) config('lsp.pdf.document_retention_days', 30)),
            'created_by_user_id' => $this->userId,
        ]);
    }
}
