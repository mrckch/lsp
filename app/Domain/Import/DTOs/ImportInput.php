<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

/**
 * Roh-Eingabe für einen Importer (Datei-Pfad oder Inhalt + Optionen).
 */
final class ImportInput
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $filename,
        public readonly bool $ignoreFirstRow = true,
        public readonly string $delimiter = ';',
        // Für API-basierte Importer (SVWS): Verweis auf eine konfigurierte ImportSource.
        // CSV-Importer ignorieren das Feld.
        public readonly ?int $sourceId = null,
    ) {}
}
