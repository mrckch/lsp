<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

/**
 * Ergebnis der Diff-Phase: Liste der geplanten Aktionen.
 */
final class DiffSet
{
    public function __construct(
        public readonly int $importJobId,
        public readonly int $totalEntries,
        public readonly int $createCount,
        public readonly int $updateCount,
        public readonly int $archiveCount,
        public readonly int $skipCount,
        public readonly int $errorCount,
    ) {}
}
