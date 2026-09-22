<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\SupportThreshold\Models\SupportThreshold;

/**
 * Verteilungskennzahlen für LQ-Werte: Quantile (R-Typ 7), Tukey-Whisker,
 * Mittelwert/SD und Förderbereiche aus den aktiven Förderbedarfsschwellen.
 * Gemeinsam genutzt von Monitor-Boxplot, Datenanalyse und PDF.
 */
final class DistributionStats
{
    /** LQ-Grenze „auffällig“, falls keine Schwellen gepflegt sind (vgl. DefaultSupportThresholdsSeeder) */
    public const THRESHOLD = 85;

    public const NORM_MEAN = 100;

    public const GENDER_LABELS = ['w' => 'Mädchen', 'm' => 'Jungen', 'other' => 'divers/unbekannt'];

    public const SEVERITY_LABELS = ['foerderbedarf' => 'Förderbedarf', 'auffaellig' => 'auffällig', 'hinweis' => 'Hinweis'];

    private const SEVERITY_RANK = ['foerderbedarf' => 3, 'auffaellig' => 2, 'hinweis' => 1];

    /**
     * Fünf-Punkte-Zusammenfassung + Tukey-Whisker (1,5 × IQR).
     *
     * @param  list<int|float>  $sorted  aufsteigend sortiert, nicht leer
     * @return array{min: float, q1: float, median: float, q3: float, max: float, lo: float, hi: float, below: int}
     */
    public static function stats(array $sorted, int $threshold = self::THRESHOLD): array
    {
        $q1 = self::quantile($sorted, 0.25);
        $q3 = self::quantile($sorted, 0.75);
        $iqr = $q3 - $q1;
        $inside = array_values(array_filter($sorted, fn ($v) => $v >= $q1 - 1.5 * $iqr && $v <= $q3 + 1.5 * $iqr));

        return [
            'min' => (float) $sorted[0],
            'q1' => $q1,
            'median' => self::quantile($sorted, 0.5),
            'q3' => $q3,
            'max' => (float) $sorted[count($sorted) - 1],
            'lo' => (float) ($inside[0] ?? $sorted[0]),
            'hi' => (float) ($inside[count($inside) - 1] ?? $sorted[count($sorted) - 1]),
            'below' => count(array_filter($sorted, fn ($v) => $v < $threshold)),
        ];
    }

    /**
     * stats() plus n, Mittelwert, Stichproben-SD und Ausreißer; null bei leerer Liste.
     *
     * @param  list<int|float>  $values  unsortiert
     * @return array{n: int, mean: float, sd: ?float, min: float, q1: float, median: float, q3: float, max: float, lo: float, hi: float, below: int, outliers: list<int|float>}|null
     */
    public static function summary(array $values, int $threshold = self::THRESHOLD): ?array
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mean = array_sum($values) / $n;
        $sd = $n > 1
            ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1))
            : null;
        $s = self::stats($values, $threshold);

        return ['n' => $n, 'mean' => $mean, 'sd' => $sd] + $s + [
            'outliers' => array_values(array_filter($values, fn ($v) => $v < $s['lo'] || $v > $s['hi'])),
        ];
    }

    /**
     * Quantil mit linearer Interpolation (wie Excel QUARTIL.INKL / R type 7).
     *
     * @param  list<int|float>  $sorted
     */
    public static function quantile(array $sorted, float $p): float
    {
        $pos = (count($sorted) - 1) * $p;
        $lower = (int) floor($pos);
        $upper = (int) ceil($pos);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($pos - $lower);
    }

    /**
     * Bereiche aus den aktiven Förderbedarfsschwellen (Metrik „LQ absolut“,
     * Operator < oder ≤), aufsteigend, plus Restbereich „unauffällig“.
     * Je Bereich die Anzahl der Werte darin.
     *
     * @param  list<int|float>  $lqs
     * @return list<array{from: ?int, to: ?int, label: string, severity: string, count: int}>
     */
    public static function bands(array $lqs): array
    {
        return array_map(fn ($b) => $b + ['count' => count(array_filter(
            $lqs,
            fn ($v) => self::inBand($b, $v),
        ))], self::bandDefinitions());
    }

    /**
     * Förderbereich (severity) eines einzelnen Werts.
     */
    public static function severityOf(int|float $lq): string
    {
        foreach (self::bandDefinitions() as $b) {
            if (self::inBand($b, $lq)) {
                return $b['severity'];
            }
        }

        return 'none';
    }

    public static function severityLabel(string $severity): string
    {
        return self::SEVERITY_LABELS[$severity] ?? 'unauffällig';
    }

    /**
     * Referenzlinie: Untergrenze des obersten Förderbereichs (z. B. „auffällig < 85“).
     *
     * @param  list<array{from: ?int, to: ?int, label: string, severity: string}>  $bands
     * @return array{0: int, 1: string}
     */
    public static function thresholdOf(array $bands): array
    {
        $topCut = count($bands) > 1 ? $bands[count($bands) - 2] : null;

        return $topCut !== null ? [$topCut['to'] + 1, $topCut['label']] : [self::THRESHOLD, 'auffällig'];
    }

    /**
     * Grenze für „auffällige Verschlechterung“ aus der aktiven Δ-Schwelle (Standard: Δ < −10).
     *
     * @return array{0: int, 1: string} Wert und Operator (lt | le)
     */
    public static function deltaCut(): array
    {
        return once(function () {
            $t = SupportThreshold::query()
                ->where('is_active', true)
                ->where('metric', 'lq_delta')
                ->whereIn('operator', ['lt', 'le'])
                ->orderByDesc('value')
                ->first();

            return $t === null ? [-10, 'lt'] : [(int) $t->value, (string) $t->operator];
        });
    }

    /**
     * Achsenbereich: mindestens 70–130, auf Zehner gerundet.
     *
     * @param  list<int|float>  $lqs
     * @return array{0: int, 1: int}
     */
    public static function domain(array $lqs): array
    {
        $min = min([70, ...$lqs]);
        $max = max([130, ...$lqs]);

        return [(int) (floor(($min - 5) / 10) * 10), (int) (ceil(($max + 5) / 10) * 10)];
    }

    /**
     * @return list<array{from: ?int, to: ?int, label: string, severity: string}>
     */
    private static function bandDefinitions(): array
    {
        // Einmal je Request laden (Gruppen/Punkte fragen den Bereich vielfach ab)
        return once(fn () => self::loadBandDefinitions());
    }

    /**
     * @return list<array{from: ?int, to: ?int, label: string, severity: string}>
     */
    private static function loadBandDefinitions(): array
    {
        // Grenze (erster Wert, der NICHT mehr dazugehört) → höchste Schwere
        $cuts = [];
        $thresholds = SupportThreshold::query()
            ->where('is_active', true)
            ->where('metric', 'lq_absolute')
            ->whereIn('operator', ['lt', 'le'])
            ->get();
        foreach ($thresholds as $t) {
            $cut = (int) floor((float) $t->value) + ($t->operator === 'le' ? 1 : 0);
            $rank = self::SEVERITY_RANK[$t->severity];
            if (! isset($cuts[$cut]) || $rank > self::SEVERITY_RANK[$cuts[$cut]]) {
                $cuts[$cut] = $t->severity;
            }
        }
        ksort($cuts);

        $bands = [];
        $from = null;
        foreach ($cuts as $cut => $severity) {
            $bands[] = ['from' => $from, 'to' => $cut - 1, 'label' => self::SEVERITY_LABELS[$severity], 'severity' => $severity];
            $from = $cut;
        }
        $bands[] = ['from' => $from, 'to' => null, 'label' => 'unauffällig', 'severity' => 'none'];

        return $bands;
    }

    /** @param  array{from: ?int, to: ?int}  $b */
    private static function inBand(array $b, int|float $v): bool
    {
        return ($b['from'] === null || $v >= $b['from']) && ($b['to'] === null || $v < $b['to'] + 1);
    }
}
