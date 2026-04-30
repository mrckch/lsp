<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

/**
 * Roh-Eingabe für einen Importer (Datei-Pfad oder Inhalt + Optionen).
 */
final class ImportInput
{
    /** SekI-Stufenfilter (Klassen 5–10) — Default für LSP-relevante Diagnostik. */
    public const SEK_I_GRADES = ['05', '06', '07', '08', '09', '10'];

    public function __construct(
        public readonly string $filePath,
        public readonly string $filename,
        public readonly bool $ignoreFirstRow = true,
        public readonly string $delimiter = ';',
        // Für API-basierte Importer (SVWS): Verweis auf eine konfigurierte ImportSource.
        // CSV-Importer ignorieren das Feld.
        public readonly ?int $sourceId = null,
        // Optionaler Stufenfilter (Liste von 'jahrgang'-Werten wie '05', '06', ...).
        // Leer/null = alle Stufen importieren. Wirkt aktuell nur im SvwsApiImporter.
        public readonly ?array $gradeFilter = null,
    ) {}
}
