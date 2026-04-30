<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Hard-Delete-Phase des DSGVO-Lifecycles für Audit-Einträge.
 *
 * Löscht endgültig Audit-Einträge, die seit mindestens X Tagen
 * (Default: config('lsp.audit.purge_after_days') = 730 Tage / 2 Jahre)
 * den Status `archived_at` tragen. Schreibt eigenen Audit-Eintrag mit
 * Zähler — der bleibt natürlich erhalten und wird nicht direkt mit
 * gepurged.
 *
 * Schwelle bezieht sich auf `archived_at`, NICHT auf `created_at` —
 * d. h. ein Eintrag wird erst gepurged, wenn er lang genug archiviert
 * war. Die Soft-Archive-Phase aus 'audit:archive' geht voraus.
 */
class PurgeArchivedAuditLogsCommand extends Command
{
    protected $signature = 'audit:purge
                            {--days= : Tage seit Archivierung; Default: config(lsp.audit.purge_after_days)}
                            {--dry-run : Nur anzeigen, was gepurged würde}';

    protected $description = 'Löscht endgültig Audit-Einträge, die seit X Tagen archiviert sind (DSGVO-Hard-Delete).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = (int) ($this->option('days') ?? config('lsp.audit.purge_after_days', 730));

        if ($days < 1) {
            $this->error('--days muss mindestens 1 sein. Auf 0 in der Config setzen, um Purge ganz zu deaktivieren.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = AuditLog::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info('Keine Einträge zum Purgen (Schwelle: '.$cutoff->format('d.m.Y H:i').').');

            return self::SUCCESS;
        }

        $this->info("Schwelle: $days Tage seit Archivierung (vor ".$cutoff->format('d.m.Y H:i').')');
        $this->info("Kandidaten: $count Einträge.");

        if ($dry) {
            $this->warn('Dry-Run: nichts gelöscht.');

            return self::SUCCESS;
        }

        $purged = $query->delete();

        AuditLog::create([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'action' => 'audit.purge',
            'entity_type' => 'audit_log',
            'entity_id' => null,
            'context' => ['purged' => $purged, 'cutoff_days' => $days],
            'includes_clearnames' => false,
        ]);

        $this->info("Gepurged: $purged Einträge.");

        return self::SUCCESS;
    }
}
