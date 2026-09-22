<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Attempt\TestEngine;
use Illuminate\Console\Command;

/**
 * Wertet Test-Versuche, deren Zeit abgelaufen ist, die aber nie abgegeben wurden
 * (z. B. Browser geschlossen). Läuft minütlich über den Scheduler.
 */
class FinalizeExpiredAttemptsCommand extends Command
{
    protected $signature = 'attempts:finalize-expired';

    protected $description = 'Gibt laufende Test-Versuche mit abgelaufener Zeit automatisch ab und wertet sie.';

    public function handle(TestEngine $engine): int
    {
        $count = $engine->finalizeExpiredAttempts();
        if ($count > 0) {
            $this->info("$count abgelaufene(n) Versuch(e) gewertet.");
        }

        return self::SUCCESS;
    }
}
