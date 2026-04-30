<?php

declare(strict_types=1);

namespace App\Domain\Import\Adapters;

use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\SvwsApiClient;
use Illuminate\Http\Client\RequestException;

/**
 * SVWS-NRW-Importer (Adapter für die SVWS-Server-REST-API).
 *
 * Holt Schüler- und Klassenstammdaten direkt aus dem SVWS-Server. Zugang wird
 * über eine konfigurierte ImportSource (type='svws_api') geliefert; die Auswahl
 * erfolgt im ImportWizard.
 *
 * `ImportInput::sourceId` muss auf die zu nutzende ImportSource verweisen.
 * `ImportInput::gradeFilter` erlaubt es, nur bestimmte Stufen (z. B. SekI 05–10)
 * zu importieren — Schüler außerhalb der Filterstufe werden weder angelegt
 * noch archiviert.
 *
 * Match-Anker: external_student_id (= SVWS-Schüler-ID), external_id_source='svws'.
 *
 * Diff/Commit-Logik liegt in AbstractStudentImporter; diese Klasse implementiert
 * nur das Quellen-spezifische API-Fetching.
 */
final class SvwsApiImporter extends AbstractStudentImporter
{
    public function key(): string
    {
        return 'svws_api';
    }

    protected function externalIdSource(): string
    {
        return 'svws';
    }

    protected function fetchRows(ImportInput $input): array
    {
        if ($input->sourceId === null) {
            throw new \InvalidArgumentException('SvwsApiImporter benötigt eine ImportSource (sourceId).');
        }

        $source = ImportSource::query()->findOrFail($input->sourceId);
        if ($source->type !== 'svws_api' || ! $source->is_active) {
            throw new \RuntimeException('Importquelle ist nicht aktiv oder nicht vom Typ svws_api.');
        }

        $client = new SvwsApiClient($source);

        try {
            $school = $client->fetchSchoolInfo();
            $abschnittId = (int) ($school['idSchuljahresabschnitt'] ?? 0);
            if ($abschnittId === 0) {
                throw new \RuntimeException('SVWS-Stammdaten enthalten keinen idSchuljahresabschnitt.');
            }
            $students = $client->fetchStudents($abschnittId);
            $classes = $client->fetchClasses($abschnittId);
        } catch (RequestException $e) {
            throw new \RuntimeException(
                'SVWS-API-Aufruf fehlgeschlagen: '.$e->response->status().' '.$e->getMessage(),
                previous: $e,
            );
        }

        $classById = [];
        foreach ($classes as $c) {
            $classById[(int) $c['id']] = (string) ($c['kuerzel'] ?? '');
        }

        $gradeFilter = $input->gradeFilter; // null = keine Filterung
        $rows = [];
        foreach ($students as $i => $s) {
            // Status 2 = aktiver Schüler (SVWS-Konvention). 8/10 = abgemeldet/Externer
            if ((int) ($s['status'] ?? 0) !== 2) {
                continue;
            }
            $jahrgang = (string) ($s['jahrgang'] ?? '');
            // Stufenfilter (z. B. SekI 05–10) — wirkt VOR Diff: Schüler außerhalb
            // werden nicht berücksichtigt, weder als create noch als archive.
            if ($gradeFilter !== null && $gradeFilter !== [] && ! in_array($jahrgang, $gradeFilter, true)) {
                continue;
            }
            $rows[] = [
                'row_number' => $i + 1,
                'raw' => $s,
                'external_student_id' => (string) ($s['id'] ?? ''),
                'last_name' => trim((string) ($s['nachname'] ?? '')),
                'first_name' => trim((string) ($s['vorname'] ?? '')),
                'gender' => $this->mapGender((string) ($s['geschlecht'] ?? '')),
                'group_name' => $classById[(int) ($s['idKlasse'] ?? 0)] ?? '',
                'jahrgang' => $jahrgang,
            ];
        }

        return $rows;
    }
}
