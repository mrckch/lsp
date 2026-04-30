<?php

declare(strict_types=1);

namespace App\Domain\NormTable;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use App\Domain\NormTable\Models\NormTable;
use App\Models\User;

/**
 * Stellt LQ-Neuberechnungen für ganze Test-Runs oder ganze Normtabellen
 * zur Verfügung. Delegiert pro Versuch an TestEngine::recalculateLq.
 */
final class LqRecalculationService
{
    public function __construct(private readonly TestEngine $engine) {}

    /**
     * Berechnet alle Versuche neu, die mit der gegebenen Normtabelle verknüpft
     * sind (oder per match_grade über die Stufe/Form aufgelöst werden können).
     */
    public function recalculateForNormTable(NormTable $norm, ?User $byUser = null, string $reason = 'norm_table_updated'): int
    {
        $count = 0;
        TestAttempt::query()
            ->where('norm_table_id', $norm->id)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->chunkById(200, function ($chunk) use ($norm, $reason, &$count) {
                foreach ($chunk as $attempt) {
                    $this->engine->recalculateLq($attempt, $norm, $reason);
                    $count++;
                }
            });

        return $count;
    }

    public function recalculateForRun(int $testRunId, string $reason = 'manual_recalc'): int
    {
        $count = 0;
        TestAttempt::query()
            ->where('test_run_id', $testRunId)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->chunkById(200, function ($chunk) use ($reason, &$count) {
                foreach ($chunk as $attempt) {
                    $this->engine->recalculateLq($attempt, null, $reason);
                    $count++;
                }
            });

        return $count;
    }
}
