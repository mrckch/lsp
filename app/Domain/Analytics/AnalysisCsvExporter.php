<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * CSV der Datenanalyse: Kennzahlen je Gruppe, optional Einzelwerte.
 * UTF-8 mit BOM, Semikolon, Dezimalkomma (Excel, deutsch).
 */
final class AnalysisCsvExporter
{
    /**
     * @param  array<string, mixed>  $dist  AnalysisReport::groupedDistribution()
     * @param  array<string, mixed>|null  $dev  AnalysisReport::development() – hängt Δ-Kennzahlen und -Werte an
     */
    public function toCsv(array $dist, string $filterText, bool $withValues, bool $withNames, ?array $dev = null): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        $put = fn (array $row) => fputcsv($fh, $row, ';', '"', '');
        $num = fn ($v, int $d = 1) => $v === null ? '' : number_format((float) $v, $d, ',', '');

        $put(['Datenanalyse Lese-Screening']);
        $put(['Filter', $filterText]);
        $put([]);

        $sevCols = array_values(array_filter($dist['bands'], fn ($b) => $b['severity'] !== 'none'));
        $header = ['Gruppe', 'Untergruppe', 'n', 'Mittelwert', 'SD', 'Median', 'Q1', 'Q3', 'Min', 'Max'];
        foreach ($sevCols as $b) {
            $header[] = 'Anzahl '.$b['label'];
            $header[] = '% '.$b['label'];
        }
        $put($header);

        foreach ([...$dist['groups'], $dist['total']] as $g) {
            $s = $g['summary'];
            $line = [
                $g['label'], $g['sublabel'] ?? '', $g['n'],
                $num($s['mean'] ?? null), $num($s['sd'] ?? null), $num($s['median'] ?? null),
                $num($s['q1'] ?? null), $num($s['q3'] ?? null), $num($s['min'] ?? null, 0), $num($s['max'] ?? null, 0),
            ];
            foreach ($sevCols as $b) {
                $c = $g['band_counts'][$b['severity']] ?? 0;
                $line[] = $c;
                $line[] = $g['n'] > 0 ? $num($c / $g['n'] * 100) : '';
            }
            $put($line);
        }

        if ($withValues) {
            $put([]);
            $put(['Einzelwerte']);
            $put(array_values(array_filter([
                'Gruppe', 'Untergruppe', 'Schülercode', $withNames ? 'Name' : null, 'Lerngruppe', 'Jahrgang', 'Geschlecht',
                'Wiederholer', 'Testdurchlauf', 'Erhebung', 'Parallelform', 'Abgegeben', 'LQ', 'Rohwert', 'Bearbeitet', 'Förderbereich',
            ])));
            foreach ($dist['groups'] as $g) {
                foreach (AnalysisReport::studentList($g['rows']) as $r) {
                    $line = [$g['label'], $g['sublabel'] ?? '', $r['student_code']];
                    if ($withNames) {
                        $line[] = $r['name'];
                    }
                    $put([...$line,
                        $r['learning_group_name'], $r['grade_level'] ?? '', $r['gender_label'], $r['is_repeater'] ? 'ja' : 'nein',
                        $r['test_run_name'], $r['assessment_type'], $r['parallel_form'] ?? '',
                        $r['submitted_at']?->format('d.m.Y'), $r['lq'], $r['raw'] ?? '', $r['answered_count'], $r['severity_label'],
                    ]);
                }
            }
        }

        if ($dev !== null && ($dev['enough'] ?? false)) {
            $put([]);
            $put(['Entwicklung', $dev['from_label'].' → '.$dev['to_label']]);
            $put(['Gruppe', 'n (beide Erhebungen)', 'MW Δ', 'Median Δ', 'verbessert', 'verschlechtert', 'auffällig verschlechtert']);
            foreach ([...$dev['delta_groups'], $dev['delta_total']] as $g) {
                $put([$g['label'], $g['n'], $num($g['summary']['mean'] ?? null), $num($g['summary']['median'] ?? null), $g['improved'], $g['declined'], $g['flagged']]);
            }
            if ($withValues) {
                $put([]);
                $put(['Verschlechterungen je Schüler']);
                $put(array_values(array_filter(['Schülercode', $withNames ? 'Name' : null, 'Lerngruppe', 'LQ '.$dev['from_label'], 'LQ '.$dev['to_label'], 'Δ', 'auffällig'])));
                foreach ($dev['declines'] as $r) {
                    $line = [$r['student_code']];
                    if ($withNames) {
                        $line[] = $r['name'];
                    }
                    $put([...$line, $r['learning_group_name'], $r['lq_from'], $r['lq'], $r['delta'], $r['flagged'] ? 'ja' : 'nein']);
                }
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
