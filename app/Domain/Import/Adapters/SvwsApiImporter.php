<?php

declare(strict_types=1);

namespace App\Domain\Import\Adapters;

use App\Domain\Import\Contracts\StudentImporter;
use App\Domain\Import\DTOs\CommitResult;
use App\Domain\Import\DTOs\DiffSet;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\DTOs\ValidationResult;

/**
 * SVWS-NRW-Importer (Adapter für die SVWS-Server-REST-API).
 *
 * Status: Architektonisch vorbereitet. Aktive SVWS-Anbindung erfordert
 * einen erreichbaren SVWS-Server und einen passenden API-Token. Konfigurationswerte
 * liegen in `import_sources` (key=svws_api).
 *
 * Implementierung: Holt Schülerdaten aus den SVWS-Endpunkten:
 *   GET /db/{schema}/schueler          → Stammdaten
 *   GET /db/{schema}/klassen           → Klassenzuordnung
 *
 * und transformiert sie in das gleiche interne Datenformat wie der SchildCsvImporter,
 * sodass der gemeinsame Diff-/Commit-Pfad genutzt werden kann.
 */
final class SvwsApiImporter implements StudentImporter
{
    public function key(): string
    {
        return 'svws_api';
    }

    public function validate(ImportInput $input): ValidationResult
    {
        throw new \RuntimeException(
            'SVWS-API-Importer ist noch nicht aktiv. Konfiguriere zunächst eine SVWS-Verbindung '.
            'in den Importquellen (Admin > Importquellen) und implementiere den HTTP-Aufruf hier.',
        );
    }

    public function diff(ImportInput $input, int $schoolYearId, string $groupType): DiffSet
    {
        throw new \RuntimeException('SVWS-API-Importer noch nicht aktiv.');
    }

    public function commit(int $importJobId, array $decisions): CommitResult
    {
        throw new \RuntimeException('SVWS-API-Importer noch nicht aktiv.');
    }
}
