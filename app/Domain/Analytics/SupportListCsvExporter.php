<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * Erzeugt CSV-Strings aus Förderbedarfslisten-Rows.
 * UTF-8 mit BOM für Excel-Kompatibilität, Semikolon-getrennt (DE-Standard).
 */
final class SupportListCsvExporter
{
    /** @param  list<array<string,mixed>>  $rows */
    public function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        // BOM für Excel
        fwrite($fh, "\xEF\xBB\xBF");
        // Header
        fputcsv($fh, ['Code', 'Name', 'Klasse', 'Stufe', 'Letzter Test', 'LQ', 'Schweregrad', 'Schwelle'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['student_code'] ?? '',
                $row['student_name'] ?? '',
                $row['group'] ?? '',
                $row['grade_level'] ?? '',
                $row['date'] ?? '',
                $row['lq'] ?? '',
                $this->severityLabel($row['severity'] ?? ''),
                $row['threshold_name'] ?? '',
            ], ';', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    private function severityLabel(string $s): string
    {
        return match ($s) {
            'foerderbedarf' => 'Förderbedarf',
            'auffaellig' => 'auffällig',
            'hinweis' => 'Hinweis',
            default => $s,
        };
    }
}
