<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Soft-archiviert Audit-Einträge, die älter sind als config('lsp.audit.archive_after_days')
 * (Default 90 Tage) — setzt nur archived_at, löscht keine Daten.
 *
 * Wird täglich vom Scheduler aufgerufen, kann auch manuell laufen.
 */
class ArchiveAuditLogsCommand extends Command
{
    protected $signature = 'audit:archive
                            {--days= : Tage; Default: config(lsp.audit.archive_after_days)}
                            {--dry-run : Nur anzeigen, was archiviert würde}';

    protected $description = 'Markiert Audit-Einträge älter als X Tage als archiviert (soft).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = (int) ($this->option('days') ?? config('lsp.audit.archive_after_days', 90));

        if ($days < 1) {
            $this->error('--days muss mindestens 1 sein.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $candidates = AuditLog::query()
            ->active()
            ->where('created_at', '<', $cutoff);

        $count = $candidates->count();

        if ($count === 0) {
            $this->info('Keine Einträge zum Archivieren (Schwelle: '.$cutoff->format('d.m.Y H:i').').');

            return self::SUCCESS;
        }

        $this->info("Schwelle: $days Tage (vor ".$cutoff->format('d.m.Y H:i').')');
        $this->info("Kandidaten: $count Einträge.");

        if ($dry) {
            $this->warn('Dry-Run: nichts geändert.');

            return self::SUCCESS;
        }

        $now = now();
        $updated = $candidates->update(['archived_at' => $now]);

        AuditLog::create([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'action' => 'audit.archive',
            'entity_type' => 'audit_log',
            'entity_id' => null,
            'context' => ['archived' => $updated, 'cutoff_days' => $days],
            'includes_clearnames' => false,
        ]);

        $this->info("Archiviert: $updated Einträge.");

        return self::SUCCESS;
    }
}
