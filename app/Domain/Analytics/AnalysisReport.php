<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Illuminate\Support\Collection;

/**
 * Aggregiert Analyse-Zeilen zu darstellbaren Strukturen (reine Arrays),
 * gemeinsam genutzt von Seite, PDF und CSV.
 */
final class AnalysisReport
{
    /** Gruppen darunter bekommen keine Box, nur Einzelpunkte */
    public const MIN_GROUP_SIZE = 3;

    /**
     * Verteilung je Gruppe (optional zweistufig, z. B. Klasse × Geschlecht).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{groups: list<array<string, mixed>>, total: array<string, mixed>, bands: list<array<string, mixed>>, domain: array{0: int, 1: int}, threshold: int, threshold_label: string}
     */
    public function groupedDistribution(Collection $rows, string $groupBy, ?string $secondary = null): array
    {
        $bands = DistributionStats::bands($rows->pluck('lq')->all());
        [$threshold, $thresholdLabel] = DistributionStats::thresholdOf($bands);

        $grouped = $rows->groupBy(fn (array $r) => self::keyOf($r, $groupBy)
            .($secondary !== null ? '|'.self::keyOf($r, $secondary) : ''));

        $groups = $grouped->map(function (Collection $items, string $key) use ($groupBy, $secondary, $threshold) {
            $first = $items->first();
            $label = self::labelOf($first, $groupBy);
            $sub = $secondary !== null ? self::labelOf($first, $secondary) : null;

            return $this->group($items, $key, $label, $sub, $threshold) + [
                'sort' => self::sortOf($first, $groupBy).' | '.($secondary !== null ? self::sortOf($first, $secondary) : ''),
            ];
        })->sortBy('sort', SORT_NATURAL | SORT_FLAG_CASE)->values()->map(fn (array $g) => array_diff_key($g, ['sort' => true]))->all();

        return [
            'groups' => $groups,
            'total' => $this->group($rows, 'total', 'Gesamt', null, $threshold),
            'bands' => $bands,
            'domain' => DistributionStats::domain($rows->pluck('lq')->all()),
            'threshold' => $threshold,
            'threshold_label' => $thresholdLabel,
        ];
    }

    /**
     * Schülerliste einer Gruppe, niedrigster LQ zuerst.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function studentList(Collection $rows): array
    {
        return $rows->sortBy([['lq', 'asc'], ['name', 'asc']])
            ->map(fn (array $r) => $r + [
                'severity' => DistributionStats::severityOf($r['lq']),
                'severity_label' => DistributionStats::severityLabel(DistributionStats::severityOf($r['lq'])),
                'gender_label' => DistributionStats::GENDER_LABELS[$r['gender']],
            ])->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function group(Collection $items, string $key, string $label, ?string $sub, int $threshold): array
    {
        $lqs = $items->pluck('lq')->all();
        $bandCounts = [];
        foreach (DistributionStats::bands($lqs) as $b) {
            $bandCounts[$b['severity']] = $b['count'];
        }

        return [
            'key' => $key,
            'label' => $label,
            'sublabel' => $sub,
            'n' => count($lqs),
            'too_small' => count($lqs) < self::MIN_GROUP_SIZE,
            'summary' => DistributionStats::summary($lqs, $threshold),
            'band_counts' => $bandCounts,
            'gender' => $items->pluck('gender')->unique()->count() === 1 ? $items->first()['gender'] : null,
            'points' => $items->sortBy('lq')->map(fn (array $r) => [
                'lq' => $r['lq'],
                'gender' => $r['gender'],
                'label' => ($r['name'] !== '' && ! str_contains($r['name'], '***') ? $r['name'].': ' : '')
                    .'LQ '.$r['lq'].' (Rohwert '.($r['raw'] ?? '–').', '.DistributionStats::GENDER_LABELS[$r['gender']].')',
            ])->values()->all(),
            'rows' => $items->values(),
        ];
    }

    /** @param  array<string, mixed>  $r */
    public static function keyOf(array $r, string $dim): string
    {
        return match ($dim) {
            'learning_group' => 'g'.($r['learning_group_id'] ?? 0),
            'gender' => $r['gender'],
            'grade_level' => 'j'.($r['grade_level'] ?? ''),
            'test_run' => 'r'.$r['test_run_id'],
            'assessment_type' => 't'.($r['assessment_type_id'] ?? 0),
            'parallel_form' => 'p'.($r['parallel_form'] ?? ''),
            default => 'all',
        };
    }

    /** @param  array<string, mixed>  $r */
    private static function labelOf(array $r, string $dim): string
    {
        return match ($dim) {
            'learning_group' => $r['learning_group_name'],
            'gender' => DistributionStats::GENDER_LABELS[$r['gender']],
            'grade_level' => $r['grade_level'] !== null && $r['grade_level'] !== '' ? 'Jahrgang '.$r['grade_level'] : 'ohne Jahrgang',
            'test_run' => $r['test_run_name'],
            'assessment_type' => $r['assessment_type'],
            'parallel_form' => $r['parallel_form'] !== null && $r['parallel_form'] !== '' ? 'Form '.$r['parallel_form'] : 'ohne Parallelform',
            default => 'Alle',
        };
    }

    /** @param  array<string, mixed>  $r */
    private static function sortOf(array $r, string $dim): string
    {
        return match ($dim) {
            'gender' => (string) array_search($r['gender'], array_keys(DistributionStats::GENDER_LABELS), true),
            'grade_level' => str_pad((string) ($r['grade_level'] ?? 'zz'), 4, '0', STR_PAD_LEFT),
            'test_run' => ($r['test_run_date'] ?? '9999').' '.$r['test_run_name'],
            'assessment_type' => str_pad((string) min($r['assessment_type_sort'], 99999), 5, '0', STR_PAD_LEFT).' '.$r['assessment_type'],
            default => self::labelOf($r, $dim),
        };
    }
}
