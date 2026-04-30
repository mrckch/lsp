<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\Models\BackupTarget;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run {target_id?}';

    protected $description = 'Führt ein Backup für ein bestimmtes (oder alle aktiven) Backup-Ziele aus.';

    public function handle(BackupRunner $runner): int
    {
        $targets = $this->argument('target_id')
            ? BackupTarget::query()->where('id', $this->argument('target_id'))->get()
            : BackupTarget::query()->where('is_active', true)->get();

        if ($targets->isEmpty()) {
            $this->error('Kein aktives Backup-Ziel gefunden.');

            return self::FAILURE;
        }

        foreach ($targets as $target) {
            $this->info("Starte Backup für Target '{$target->name}' (#{$target->id}) ...");
            $run = $runner->run($target, 'scheduled');
            if ($run->status === 'success') {
                $this->info("  → OK: {$run->file_name} ({$run->size_bytes} bytes)");
            } else {
                $this->error("  → FEHLER: {$run->error_message}");
            }
        }

        return self::SUCCESS;
    }
}
