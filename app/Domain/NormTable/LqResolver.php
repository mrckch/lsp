<?php

declare(strict_types=1);

namespace App\Domain\NormTable;

use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;

/**
 * Bestimmt aus einem Rohwert + Geschlecht + Klassenstufe + Parallelform den LQ.
 */
final class LqResolver
{
    /**
     * @return int|null  null wenn keine passende Norm-Zeile vorhanden ist
     *                   (→ Schüler bekommt KEIN Ergebnis angezeigt)
     */
    public function resolve(int $rawScore, string $gender, ?NormTable $normTable): ?int
    {
        if ($normTable === null) {
            return null;
        }

        $row = NormTableRow::query()
            ->where('norm_table_id', $normTable->id)
            ->where('raw_score', $rawScore)
            ->first();

        if ($row === null) {
            return null;
        }

        return match ($gender) {
            'w' => $row->quotient_female,
            'm' => $row->quotient_male,
            'd' => $row->quotient_diverse ?? (int) round(($row->quotient_female + $row->quotient_male) / 2),
            default => (int) round(($row->quotient_female + $row->quotient_male) / 2),
        };
    }

    public function bestNormTableFor(string $gradeLevel, string $parallelForm): ?NormTable
    {
        return NormTable::query()
            ->where('grade_level', $gradeLevel)
            ->where('parallel_form', $parallelForm)
            ->where('is_active', true)
            ->where('status', 'aktiv')
            ->latest('id')
            ->first();
    }
}
