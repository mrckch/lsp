<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\PrintJob\Models\GeneratedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Löscht abgelaufene erzeugte Dokumente (PDF/ZIP) inkl. Datei.
 * Wird täglich vom Scheduler aufgerufen, kann auch manuell laufen.
 */
class CleanupExpiredDocumentsCommand extends Command
{
    protected $signature = 'documents:cleanup
                            {--dry-run : Nur anzeigen, was gelöscht würde}
                            {--disk=local : Storage-Disk}';

    protected $description = 'Löscht generated_documents mit abgelaufenem expires_at und die zugehörigen Dateien.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = (string) $this->option('disk');

        $expired = GeneratedDocument::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Keine abgelaufenen Dokumente gefunden.');

            return self::SUCCESS;
        }

        $this->info($expired->count().' Kandidat(en):');
        foreach ($expired as $doc) {
            $this->line(sprintf('  • #%d %s (abgelaufen %s)',
                $doc->id, $doc->file_name, optional($doc->expires_at)->format('d.m.Y')));
        }

        if ($dry) {
            $this->warn('Dry-Run: nichts gelöscht.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $missing = 0;
        foreach ($expired as $doc) {
            if ($doc->file_path && Storage::disk($disk)->exists($doc->file_path)) {
                Storage::disk($disk)->delete($doc->file_path);
            } else {
                $missing++;
            }
            $doc->delete();
            $deleted++;
        }

        AuditLog::create([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'action' => 'documents.cleanup',
            'entity_type' => 'generated_document',
            'entity_id' => null,
            'context' => ['deleted' => $deleted, 'missing_files' => $missing],
            'includes_clearnames' => false,
        ]);

        $this->info("Gelöscht: $deleted (davon $missing ohne Datei)");

        return self::SUCCESS;
    }
}
