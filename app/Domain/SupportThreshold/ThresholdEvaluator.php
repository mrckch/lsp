<?php

declare(strict_types=1);

namespace App\Domain\SupportThreshold;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Student\Models\Student;
use App\Domain\SupportThreshold\Models\SupportThreshold;

/**
 * Wendet konfigurierte Schwellen auf Schüler-Versuche an und liefert Treffer
 * (mit höchster severity gewinnt).
 */
final class ThresholdEvaluator
{
    /**
     * Liefert für jeden aktiven Schüler max. einen Treffer (höchste severity).
     *
     * @return array<int, array{student:Student, attempt:TestAttempt, threshold:SupportThreshold}>
     */
    public function evaluateAll(?int $schoolYearId = null): array
    {
        $thresholds = SupportThreshold::query()->where('is_active', true)->get();
        if ($thresholds->isEmpty()) {
            return [];
        }

        $studentsQ = Student::query()->where('status', 'aktiv');
        if ($schoolYearId !== null) {
            $studentsQ->whereHas('enrollments', fn ($q) => $q->where('school_year_id', $schoolYearId));
        }

        $hits = [];
        foreach ($studentsQ->cursor() as $student) {
            $attempts = TestAttempt::query()
                ->where('student_id', $student->id)
                ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
                ->whereNotNull('lq_current')
                ->orderByDesc('submitted_at')
                ->get();
            if ($attempts->isEmpty()) {
                continue;
            }

            $best = null; // höchste severity
            foreach ($thresholds as $threshold) {
                $attempt = $this->matches($threshold, $attempts);
                if ($attempt !== null) {
                    if ($best === null || $this->severityRank($threshold->severity) > $this->severityRank($best['threshold']->severity)) {
                        $best = ['student' => $student, 'attempt' => $attempt, 'threshold' => $threshold];
                    }
                }
            }
            if ($best !== null) {
                $hits[] = $best;
            }
        }

        return $hits;
    }

    private function matches(SupportThreshold $threshold, $attempts): ?TestAttempt
    {
        $latest = $attempts->first();

        return match ($threshold->metric) {
            'lq_absolute' => $this->compare($latest->lq_current, $threshold->operator, $threshold->value)
                ? $latest : null,
            'lq_delta' => $this->matchesDelta($threshold, $attempts),
            default => null,
        };
    }

    private function matchesDelta(SupportThreshold $threshold, $attempts): ?TestAttempt
    {
        $window = max(2, $threshold->window_count ?? 2);
        if ($attempts->count() < $window) {
            return null;
        }
        $sub = $attempts->take($window);
        $delta = $sub->first()->lq_current - $sub->last()->lq_current;

        return $this->compare($delta, $threshold->operator, $threshold->value)
            ? $sub->first() : null;
    }

    private function compare(int $a, string $op, int $b): bool
    {
        return match ($op) {
            'lt' => $a < $b,
            'le' => $a <= $b,
            'gt' => $a > $b,
            'ge' => $a >= $b,
            'eq' => $a === $b,
            default => false,
        };
    }

    private function severityRank(string $s): int
    {
        return match ($s) {
            'foerderbedarf' => 3,
            'auffaellig' => 2,
            'hinweis' => 1,
            default => 0,
        };
    }
}
