<?php

declare(strict_types=1);

namespace App\Domain\TestRun;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\ScopeFilter;
use App\Domain\TestRun\Models\TestRun;
use App\Models\User;

/**
 * Fortschritt eines Testdurchlaufs aus Sicht eines Users (nur Schüler in
 * seinem Scope). Grundlage für Dashboard-Kacheln und Übersichtsseite.
 */
final class TestRunProgress
{
    public function __construct(private readonly ScopeFilter $scope) {}

    /**
     * @return array{total: int, not_started: int, running: int, finished: int, avg_lq: ?int, avg_score: ?float}
     */
    public function summarize(TestRun $run, User $user): array
    {
        $codes = $this->scope->applyToLoginCodes(
            StudentLoginCode::query()->where('test_run_id', $run->id),
            $user,
        )->pluck('status');

        $attempts = $this->scope->applyToAttempts(
            TestAttempt::query()->where('test_run_id', $run->id),
            $user,
        );
        // Je Schüler nur der letzte gewertete Versuch
        $finished = (clone $attempts)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->orderByDesc('id')
            ->get(['id', 'student_id', 'lq_current', 'score_raw'])
            ->unique('student_id');
        $avgLq = $finished->whereNotNull('lq_current')->avg('lq_current');
        $avgScore = $finished->avg('score_raw');

        return [
            'total' => $codes->count(),
            'not_started' => $codes->filter(fn ($s) => $s === 'aktiv')->count(),
            'running' => (clone $attempts)->where('status', 'gestartet')->count(),
            'finished' => $finished->count(),
            'avg_lq' => $avgLq !== null ? (int) round((float) $avgLq) : null,
            'avg_score' => $avgScore !== null ? (float) $avgScore : null,
        ];
    }
}
