<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Privacy\PrivacyService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * CLI für endgültige Löschung archivierter Schüler.
 *
 *   php artisan privacy:delete --min-age-days=1825
 *
 * Listet zunächst Kandidaten und löscht nur nach interaktiver Bestätigung.
 */
class PrivacyDeleteCommand extends Command
{
    protected $signature = 'privacy:delete {--min-age-days=1825} {--user-id=}';

    protected $description = 'Listet/löscht archivierte Schüler älter als X Tage (Art. 17 DSGVO).';

    public function handle(PrivacyService $service): int
    {
        $minAge = (int) $this->option('min-age-days');
        $candidates = $service->listDeletionCandidates($minAge);

        if ($candidates->isEmpty()) {
            $this->info("Keine Kandidaten älter als $minAge Tage.");

            return self::SUCCESS;
        }

        $this->info($candidates->count().' Kandidat(en):');
        foreach ($candidates as $s) {
            $this->line(sprintf('  • #%d %s archiviert seit %s',
                $s->id, $s->student_code, $s->archived_at?->toDateString()));
        }

        if (! $this->confirm('Wirklich endgültig löschen?', false)) {
            $this->warn('Abgebrochen.');

            return self::FAILURE;
        }

        $userId = (int) ($this->option('user-id') ?? 0);
        $user = $userId ? User::find($userId) : User::query()->first();
        if (! $user) {
            $this->error('Kein gültiger Benutzer für Audit-Log gefunden (--user-id=).');

            return self::FAILURE;
        }

        $deleted = 0;
        foreach ($candidates as $student) {
            if ($service->deleteStudent($student, $user, 'CLI privacy:delete', confirmed: true)) {
                $deleted++;
            }
        }

        $this->info("Gelöscht: $deleted");

        return self::SUCCESS;
    }
}
