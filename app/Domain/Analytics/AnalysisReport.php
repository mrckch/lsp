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

    /** Mehr Verlaufslinien werden unübersichtlich → dann nur „Alle“ */
    public const MAX_SERIES = 6;

    /** Quadranten der Tempo/Genauigkeit-Ansicht */
    public const QUADRANTS = [
        'fast_accurate' => 'schnell & genau',
        'fast_inaccurate' => 'schnell & fehlerhaft',
        'slow_accurate' => 'langsam & genau',
        'slow_inaccurate' => 'langsam & fehlerhaft',
    ];

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
     * Entwicklung über Erhebungswellen (z. B. Herbst → Frühjahr): Median/Quartile je Welle
     * und Gruppe sowie Δ-LQ je Schüler zwischen zwei Wellen.
     * Erwartet einen Versuch je Schüler und Welle (AnalysisDataset::rows(perWave: true)).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function development(Collection $rows, string $groupBy, ?string $fromWave = null, ?string $toWave = null): array
    {
        $waves = $rows->groupBy('wave_key')
            ->map(fn (Collection $items, string $key) => [
                'key' => $key,
                'label' => $items->first()['wave_label'],
                'sort' => $items->min('wave_sort'),
                'n' => $items->count(),
            ])
            ->sortBy('sort')->values()
            ->map(fn (array $w) => array_diff_key($w, ['sort' => true]))->all();
        $keys = array_column($waves, 'key');

        $result = ['waves' => $waves, 'enough' => count($waves) >= 2];
        if (! $result['enough']) {
            return $result;
        }

        // Standard: die beiden jüngsten Wellen; „von“ liegt immer vor „bis“
        $to = in_array($toWave, $keys, true) ? $toWave : $keys[count($keys) - 1];
        $from = in_array($fromWave, $keys, true) && $fromWave !== $to
            ? $fromWave
            : ($keys[array_search($to, $keys, true) - 1] ?? $keys[1]);
        if (array_search($from, $keys, true) > array_search($to, $keys, true)) {
            [$from, $to] = [$to, $from];
        }

        // Verlaufslinien je Gruppe (max. MAX_SERIES, sonst nur „Alle“)
        $byGroup = $groupBy === 'none' ? collect() : $rows->groupBy(fn (array $r) => self::keyOf($r, $groupBy));
        $tooMany = $byGroup->count() > self::MAX_SERIES;
        $seriesSource = $byGroup->isEmpty() || $tooMany
            ? collect(['total' => $rows])
            : $byGroup->sortBy(fn (Collection $items) => self::sortOf($items->first(), $groupBy), SORT_NATURAL | SORT_FLAG_CASE);
        $series = $seriesSource->map(fn (Collection $items, string $key) => [
            'key' => $key,
            'label' => $key === 'total' ? 'Alle' : self::labelOf($items->first(), $groupBy),
            'points' => array_map(function (array $w) use ($items) {
                $lqs = $items->where('wave_key', $w['key'])->pluck('lq')->sort()->values()->all();

                return $lqs === [] ? null : [
                    'n' => count($lqs),
                    'median' => DistributionStats::quantile($lqs, 0.5),
                    'q1' => DistributionStats::quantile($lqs, 0.25),
                    'q3' => DistributionStats::quantile($lqs, 0.75),
                ];
            }, $waves),
        ])->values()->all();

        // Paare: Schüler mit Versuch in beiden Wellen; Gruppe/Name aus der späteren Welle
        $fromRows = $rows->where('wave_key', $from)->keyBy('student_id');
        [$cutValue, $cutOp] = DistributionStats::deltaCut();
        $pairs = $rows->where('wave_key', $to)
            ->filter(fn (array $r) => $fromRows->has($r['student_id']))
            ->map(function (array $r) use ($fromRows, $cutValue, $cutOp) {
                $delta = $r['lq'] - $fromRows[$r['student_id']]['lq'];

                return $r + [
                    'lq_from' => $fromRows[$r['student_id']]['lq'],
                    'delta' => $delta,
                    'flagged' => $cutOp === 'le' ? $delta <= $cutValue : $delta < $cutValue,
                ];
            })->values();

        $deltaGroups = ($groupBy === 'none' ? collect(['all' => $pairs]) : $pairs->groupBy(fn (array $r) => self::keyOf($r, $groupBy)))
            ->map(fn (Collection $items, string $key) => $this->deltaGroup($items, $key, $groupBy === 'none' ? 'Alle' : self::labelOf($items->first(), $groupBy))
                + ['sort' => $groupBy === 'none' ? '' : self::sortOf($items->first(), $groupBy)])
            ->sortBy('sort', SORT_NATURAL | SORT_FLAG_CASE)->values()
            ->map(fn (array $g) => array_diff_key($g, ['sort' => true]))->all();
        $deltas = $pairs->pluck('delta')->all();

        return $result + [
            'from' => $from,
            'to' => $to,
            'from_label' => $waves[array_search($from, $keys, true)]['label'],
            'to_label' => $waves[array_search($to, $keys, true)]['label'],
            'series' => $series,
            'too_many_series' => $tooMany,
            'domain' => DistributionStats::domain($rows->pluck('lq')->all()),
            'delta_groups' => $deltaGroups,
            'delta_total' => $this->deltaGroup($pairs, 'total', 'Gesamt'),
            'delta_domain' => [
                (int) (floor((min([-25, ...$deltas]) - 5) / 10) * 10),
                (int) (ceil((max([25, ...$deltas]) + 5) / 10) * 10),
            ],
            'delta_cut' => $cutValue,
            'delta_cut_op' => $cutOp,
            'declines' => $pairs->filter(fn (array $r) => $r['delta'] < 0)
                ->sortBy([['delta', 'asc'], ['name', 'asc']])->values()
                ->map(fn (array $r) => $r + ['gender_label' => DistributionStats::GENDER_LABELS[$r['gender']]])->all(),
        ];
    }

    /**
     * Häufigkeitsverteilung der LQ-Werte (Klassenbreite $binWidth) mit der erwarteten
     * Verteilung laut Norm N(100, 15) und beobachteten vs. erwarteten Anteilen je Förderbereich.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function histogram(Collection $rows, int $binWidth = 5): array
    {
        $lqs = $rows->pluck('lq')->all();
        $n = count($lqs);
        [$d0, $d1] = DistributionStats::domain($lqs);
        $sd = DistributionStats::NORM_SD;

        $bins = [];
        for ($from = $d0; $from < $d1; $from += $binWidth) {
            $to = $from + $binWidth - 1;
            $bins[] = [
                'from' => $from,
                'to' => $to,
                'count' => count(array_filter($lqs, fn ($v) => $v >= $from && $v <= $to)),
                // Erwartung für ganzzahlige LQ-Werte from..to (Stetigkeitskorrektur ±0,5)
                'expected' => $n * (DistributionStats::normalCdf($to + 0.5, sd: $sd) - DistributionStats::normalCdf($from - 0.5, sd: $sd)),
            ];
        }

        // Normkurve als Dichte, skaliert auf „Anzahl je Klasse“
        $curve = [];
        for ($x = $d0; $x <= $d1; $x += 1) {
            $curve[] = [$x, $n * $binWidth * exp(-0.5 * (($x - DistributionStats::NORM_MEAN) / $sd) ** 2) / ($sd * sqrt(2 * M_PI))];
        }

        $bands = DistributionStats::bands($lqs);
        $shares = array_map(function (array $b) use ($n, $sd) {
            $lo = $b['from'] === null ? 0.0 : DistributionStats::normalCdf($b['from'] - 0.5, sd: $sd);
            $hi = $b['to'] === null ? 1.0 : DistributionStats::normalCdf($b['to'] + 0.5, sd: $sd);

            return $b + [
                'observed_pct' => $n > 0 ? (float) ($b['count'] / $n * 100) : 0.0,
                'expected_pct' => ($hi - $lo) * 100,
                'expected_count' => ($hi - $lo) * $n,
            ];
        }, $bands);

        return [
            'n' => $n,
            'bins' => $bins,
            'curve' => $curve,
            'domain' => [$d0, $d1],
            'bin_width' => $binWidth,
            'summary' => DistributionStats::summary($lqs),
            'shares' => $shares,
            'max' => max([1, ...array_column($bins, 'count'), ...array_column($curve, 1)]),
        ];
    }

    /**
     * Tempo (bearbeitete Sätze) gegen Genauigkeit (Fehlerquote) je Schüler, Quadranten an den Medianen.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function speedAccuracy(Collection $rows): array
    {
        $points = $rows->filter(fn (array $r) => $r['answered_count'] > 0)
            ->map(fn (array $r) => $r + [
                'errors' => max(0, $r['answered_count'] - $r['raw']),
                'error_rate' => max(0, $r['answered_count'] - $r['raw']) / $r['answered_count'] * 100,
                'severity' => DistributionStats::severityOf($r['lq']),
            ])->values();

        if ($points->isEmpty()) {
            return ['n' => 0, 'points' => []];
        }

        $xs = $points->pluck('answered_count')->sort()->values()->all();
        $ys = $points->pluck('error_rate')->sort()->values()->all();
        $mx = DistributionStats::quantile($xs, 0.5);
        $my = DistributionStats::quantile($ys, 0.5);

        // Quadrant: Tempo über/unter Median, Fehlerquote über/unter Median (Gleichstand zählt als „nicht über“)
        $quadrantOf = fn (array $p) => ($p['answered_count'] > $mx ? 'fast' : 'slow').'_'.($p['error_rate'] > $my ? 'inaccurate' : 'accurate');
        $points = $points->map(fn (array $p) => $p + ['quadrant' => $quadrantOf($p)]);

        return [
            'n' => $points->count(),
            'points' => $points->all(),
            'median_x' => $mx,
            'median_y' => $my,
            // x ab knapp unter dem kleinsten Wert (sonst viel Leerraum), oben Platz für die Quadranten-Beschriftung
            'x_min' => (int) max(0, floor((min($xs) - 2) / 5) * 5),
            'x_max' => (int) (ceil((max($xs) + 1) / 5) * 5),
            'y_max' => (int) max(20, ceil((max($ys) + 6) / 10) * 10),
            'quadrants' => collect(self::QUADRANTS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $points->where('quadrant', $key)->count(),
            ])->values()->all(),
            // Für Rückmeldungen: wer schnell, aber fehlerhaft liest – und wer genau, aber langsam
            'fast_inaccurate' => $points->where('quadrant', 'fast_inaccurate')->sortByDesc('error_rate')->values()->all(),
            'slow_accurate' => $points->where('quadrant', 'slow_accurate')->sortBy('answered_count')->values()->all(),
        ];
    }

    /**
     * Δ-Kennzahlen einer Gruppe; Struktur passt zur Boxplot-Komponente (Punkt-x = Δ).
     *
     * @param  Collection<int, mixed>  $items  Paare aus development()
     * @return array<string, mixed>
     */
    private function deltaGroup(Collection $items, string $key, string $label): array
    {
        $deltas = $items->pluck('delta')->all();

        return [
            'key' => $key,
            'label' => $label,
            'sublabel' => null,
            'n' => count($deltas),
            'too_small' => count($deltas) < self::MIN_GROUP_SIZE,
            'summary' => DistributionStats::summary($deltas),
            'gender' => null,
            'improved' => count(array_filter($deltas, fn ($d) => $d > 0)),
            'declined' => count(array_filter($deltas, fn ($d) => $d < 0)),
            'flagged' => $items->where('flagged', true)->count(),
            'points' => $items->sortBy('delta')->map(fn (array $r) => [
                'lq' => $r['delta'],
                'gender' => $r['gender'],
                'label' => ($r['name'] !== '' && ! str_contains($r['name'], '***') ? $r['name'].': ' : '')
                    .'LQ '.$r['lq_from'].' → '.$r['lq'].' (Δ '.($r['delta'] > 0 ? '+' : '').$r['delta'].')',
            ])->values()->all(),
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
