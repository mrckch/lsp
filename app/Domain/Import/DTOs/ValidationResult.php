<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

final class ValidationResult
{
    /**
     * @param  list<array{row_number:int, raw:array<int,string>, errors:array<int,string>, valid:bool}>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalRows,
        public readonly int $validRows,
        public readonly int $errorRows,
    ) {}
}
