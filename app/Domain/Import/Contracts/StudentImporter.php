<?php

declare(strict_types=1);

namespace App\Domain\Import\Contracts;

use App\Domain\Import\DTOs\CommitResult;
use App\Domain\Import\DTOs\DiffSet;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\DTOs\ValidationResult;

/**
 * Adapter-Interface für unterschiedliche Import-Quellen
 * (SchiLD-CSV, SVWS-API, manuell ...).
 */
interface StudentImporter
{
    public function key(): string;

    public function validate(ImportInput $input): ValidationResult;

    /**
     * Berechnet Diff zwischen Import und aktuellem Schülerbestand
     * (Anlage / Update / Archivkandidaten).
     */
    public function diff(ImportInput $input, int $schoolYearId, string $groupType): DiffSet;

    /**
     * Wendet die im DiffSet enthaltenen Aktionen mit Admin-Bestätigungen an.
     *
     * @param  array<int,string>  $decisions  diff_entry_id => 'confirm'|'exclude'
     */
    public function commit(int $importJobId, array $decisions): CommitResult;
}
