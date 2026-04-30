<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\PrintJob\BulkHistoryExporter;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Jobs\Concerns\LogsFailureToAudit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Erzeugt asynchron ein ZIP mit Verlaufs-PDFs für eine Liste von SuS
 * und persistiert es als GeneratedDocument zum Download.
 */
class GenerateBulkHistoryZipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LogsFailureToAudit;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    protected function failureContext(): array
    {
        return ['student_count' => count($this->studentIds), 'kind' => 'bulk_history_zip'];
    }

    /**
     * @param  array<int>  $studentIds
     */
    public function __construct(
        public readonly array $studentIds,
        public readonly ?int $userId,
    ) {
        $this->onQueue('pdf');
    }

    public function handle(BulkHistoryExporter $exporter): void
    {
        $user = $this->userId ? User::find($this->userId) : null;

        $result = $exporter->exportFor($this->studentIds, forUser: $user);

        if ($result['count'] === 0) {
            return;
        }

        $bytes = file_get_contents($result['zip']);
        @unlink($result['zip']);

        $filename = 'verlaeufe_'.now()->format('Ymd_His').'.zip';
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
