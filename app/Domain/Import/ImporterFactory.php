<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Adapters\SchildCsvImporter;
use App\Domain\Import\Adapters\SvwsApiImporter;
use App\Domain\Import\Contracts\StudentImporter;

/**
 * Factory zum Auflösen eines Importer-Adapters anhand des `source_key`.
 *
 * Verhindert das Hardcoding eines konkreten Adapters in der Wizard-Page.
 */
final class ImporterFactory
{
    public function make(string $sourceKey): StudentImporter
    {
        return match ($sourceKey) {
            'schild_csv' => app(SchildCsvImporter::class),
            'svws_api' => app(SvwsApiImporter::class),
            default => throw new \InvalidArgumentException("Unbekannter Importer-Key: {$sourceKey}"),
        };
    }
}
