<?php

declare(strict_types=1);

namespace App\Domain\Import\Adapters;

use App\Domain\Import\DTOs\ImportInput;

/**
 * Importer für klassische SchiLD-NRW CSV-Exporte.
 *
 * Erwartetes Format (Spalten, ;-getrennt):
 *   ID ; Name ; Vorname ; Klasse/Kurs ; Geschlecht
 *
 * Diff-/Commit-Logik liegt in AbstractStudentImporter; diese Klasse
 * implementiert nur das Quellen-spezifische CSV-Parsing.
 */
final class SchildCsvImporter extends AbstractStudentImporter
{
    public function key(): string
    {
        return 'schild_csv';
    }

    protected function externalIdSource(): string
    {
        return 'schild';
    }

    protected function defaultJobFilename(ImportInput $input): string
    {
        return $input->filename;
    }

    protected function fetchRows(ImportInput $input): array
    {
        $rawRows = $this->readCsv($input);
        $rows = [];
        foreach ($rawRows as $i => $row) {
            $rows[] = [
                'row_number' => $i + ($input->ignoreFirstRow ? 2 : 1),
                'raw' => $row,
                'external_student_id' => trim($row[0] ?? ''),
                'last_name' => trim($row[1] ?? ''),
                'first_name' => trim($row[2] ?? ''),
                'group_name' => trim($row[3] ?? ''),
                'gender' => $this->mapGender(trim($row[4] ?? '')),
                'jahrgang' => null,
            ];
        }

        return $rows;
    }

    /** @return list<array<int,string>> */
    private function readCsv(ImportInput $input): array
    {
        $rows = [];
        $handle = fopen($input->filePath, 'r');
        if (! $handle) {
            return [];
        }
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $line = 0;
        while (($row = fgetcsv($handle, 0, $input->delimiter, '"', '')) !== false) {
            $line++;
            if ($input->ignoreFirstRow && $line === 1) {
                continue;
            }
            if (count(array_filter($row, fn ($v) => $v !== '' && $v !== null)) === 0) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
