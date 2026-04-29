<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Student\Models\Student;
use Illuminate\Support\Collection;

/**
 * Aggregierte Auswertungen: Längsschnitt, Kohorten, Trends.
 */
final class AnalyticsService
{
    /**
     * Längsschnitt eines Schülers (chronologisch).
     */
    public function studentHistory(Student $student): Collection
    {
        return TestAttempt::query()
            ->with(['testRun.assessmentType', 'testRun.schoolYear'])
            ->where('student_id', $student->id)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (TestAttempt $a) => [
                'attempt_id' => $a->id,
                'submitted_at' => $a->submitted_at,
                'score_raw' => $a->score_raw,
                'lq_at_submission' => $a->lq_at_submission,
                'lq_current' => $a->lq_current,
                'parallel_form' => $a->parallel_form,
                'test_run_name' => $a->testRun?->name,
                'assessment_type' => $a->testRun?->assessmentType?->label,
                'school_year' => $a->testRun?->schoolYear?->label,
            ]);
    }

    /**
     * Aggregierte Kennzahlen einer Kohorte (z. B. Klasse / Jahrgang).
     *
     * @param  array{school_year_id?:int, learning_group_id?:int, grade_level?:string}  $filter
     */
    public function cohort(array $filter = []): array
    {
        $q = TestAttempt::query()
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->whereNotNull('lq_current');

        if (! empty($filter['learning_group_id'])) {
            $q->whereHas('testRun.learningGroups', fn ($g) => $g->where('learning_groups.id', $filter['learning_group_id']));
        }
        if (! empty($filter['school_year_id'])) {
            $q->whereHas('testRun', fn ($r) => $r->where('school_year_id', $filter['school_year_id']));
        }
        if (! empty($filter['grade_level'])) {
            $q->whereHas('testRun.learningGroups', fn ($g) => $g->where('grade_level', $filter['grade_level']));
        }

        $rows = $q->get(['lq_current', 'score_raw']);
        $count = $rows->count();
        $avgLq = $count ? round($rows->avg('lq_current'), 1) : null;
        $minLq = $rows->min('lq_current');
        $maxLq = $rows->max('lq_current');
        $median = $count ? $this->median($rows->pluck('lq_current')->all()) : null;

        return [
            'attempts' => $count,
            'avg_lq' => $avgLq,
            'median_lq' => $median,
            'min_lq' => $minLq,
            'max_lq' => $maxLq,
        ];
    }

    /**
     * Trend zwischen zwei Test-Runs: Δ-LQ pro Schüler, sortiert nach Veränderung.
     */
    public function trend(int $fromRunId, int $toRunId): Collection
    {
        $fromAttempts = TestAttempt::query()
            ->where('test_run_id', $fromRunId)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->whereNotNull('lq_current')
            ->get()
            ->keyBy('student_id');

        $toAttempts = TestAttempt::query()
            ->where('test_run_id', $toRunId)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->whereNotNull('lq_current')
            ->get()
            ->keyBy('student_id');

        $studentIds = $fromAttempts->keys()->intersect($toAttempts->keys());
        $rows = collect();
        foreach ($studentIds as $sid) {
            $a = $fromAttempts[$sid];
            $b = $toAttempts[$sid];
            $rows->push([
                'student_id' => $sid,
                'lq_from' => $a->lq_current,
                'lq_to' => $b->lq_current,
                'delta' => $b->lq_current - $a->lq_current,
            ]);
        }

        return $rows->sortBy('delta')->values();
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2
            ? (float) $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
