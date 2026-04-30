<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Models\ImportSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Dünner HTTP-Wrapper um die SVWS-NRW-Server-API.
 *
 * Auth: HTTP Basic, Realm "Authentifizierung bei dem SVWS-Server".
 * Pfade: /db/{schema}/...
 *
 * Verifiziert gegen einen lokalen SVWS-Server (Version Build-Tag aus 2025+).
 * Endpunkte:
 *   GET /db/{schema}/schule/stammdaten        → Schulinfo + idSchuljahresabschnitt
 *   GET /db/{schema}/schueler/abschnitt/{id}  → Liste aller Schüler im Abschnitt
 *   GET /db/{schema}/klassen/abschnitt/{id}   → Klassenliste im Abschnitt
 *
 * Felder, die wir aus den Schüler-Stammdaten verwenden:
 *   id (extern), nachname, vorname, geschlecht ('m'|'w'|'d'), idKlasse,
 *   jahrgang ('05'..'10'), idSchuljahresabschnitt, status (2 = aktiv)
 */
final class SvwsApiClient
{
    public function __construct(private readonly ImportSource $source) {}

    /** Schule-Stammdaten (u. a. idSchuljahresabschnitt = aktueller Abschnitt). */
    public function fetchSchoolInfo(): array
    {
        return $this->client()
            ->get($this->urlFor('schule/stammdaten'))
            ->throw()
            ->json();
    }

    /** Alle Schüler-Stammdaten eines Schuljahresabschnitts. */
    public function fetchStudents(int $idSchuljahresabschnitt): array
    {
        return $this->client()
            ->get($this->urlFor("schueler/abschnitt/{$idSchuljahresabschnitt}"))
            ->throw()
            ->json();
    }

    /** Alle Klassen eines Schuljahresabschnitts. */
    public function fetchClasses(int $idSchuljahresabschnitt): array
    {
        return $this->client()
            ->get($this->urlFor("klassen/abschnitt/{$idSchuljahresabschnitt}"))
            ->throw()
            ->json();
    }

    private function client(): PendingRequest
    {
        $cfg = $this->source->config_encrypted ?? [];
        $client = Http::withBasicAuth(
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? ''),
        )
            ->acceptJson()
            ->timeout((int) ($cfg['timeout_seconds'] ?? 20));

        if (($cfg['verify_ssl'] ?? true) === false) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function urlFor(string $path): string
    {
        $cfg = $this->source->config_encrypted ?? [];
        $base = rtrim((string) ($cfg['api_url'] ?? ''), '/');
        $schema = trim((string) ($cfg['schema'] ?? ''));

        return "{$base}/db/{$schema}/{$path}";
    }
}
