<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

final class CommitResult
{
    public function __construct(
        public readonly int $imported,
        public readonly int $updated,
        public readonly int $archived,
        public readonly int $skipped,
        public readonly int $failed,
    ) {}
}
