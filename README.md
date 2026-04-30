# LSP – Lese-Screening-Portal

> Open-Source-Webanwendung für die digitale Durchführung, Auswertung und Längsschnitt-Beobachtung von Lese-Screenings (nach dem Verfahren des Salzburger Lese-Screenings) an Schulen in Deutschland.

**Lizenz:** [EUPL 1.2](LICENSE)
**Sprache:** Deutsch
**Status:** funktionsfähig (alle Phasen 0–5 abgeschlossen) · 271 Unit-/Feature-Tests + 10 E2E-Browser-Tests grün · `composer lint` (Pint + PHPStan Level 5) sauber

---

## Auf einen Blick

- ✅ Setup-Wizard mit Klarnamen-Verschlüsselung (Envelope-Encryption, Argon2id + AES-256-GCM)
- ✅ Granulares Permission-Modell mit Klassen, Scopes und User-Overrides
- ✅ Optionales 2FA (TOTP) mit Klassen-erzwingbarer Pflicht
- ✅ Schüler-Test mit Code-basierter Anmeldung (10-stelliger One-Shot-Code), JS-Timer, Auto-Submit
- ✅ Längsschnitt-Beobachtung pro Schüler über mehrere Schuljahre
- ✅ Förderbedarfs-Liste mit konfigurierbaren Schwellen
- ✅ PDF-Generierung via Gotenberg (Bulk-Rückmeldungen, Verlaufsdiagramme)
- ✅ Audit-Log mit Soft-Archivierung (Cron) + Hard-Delete-Cron für DSGVO-Lifecycle
- ✅ Backup mit AES-256-GCM-Verschlüsselung, JSON-Dump (DB) + Storage-Files; Restore inkl. Pre-Snapshot
- ✅ Import-Adapter: SchiLD-CSV + SVWS-NRW-API (live verifiziert)
- ✅ Welcome-Mail-Onboarding mit Force-Password-Change
- ✅ Recovery-Key-Verwaltung im UI (Regenerate, Status-Übersicht)

---

## Dokumentation

Die vollständige Spezifikation liegt im [`docs/`](docs/)-Verzeichnis:

- [01 – Pflichtenheft](docs/01-pflichtenheft.md)
- [02 – Datenmodell](docs/02-datenmodell.md)
- [03 – Berechtigungskatalog](docs/03-permissions.md)
- [04 – API-Spec (OpenAPI 3.1)](docs/04-api-spec.yaml)
- [05 – Stack & Architektur](docs/05-stack-architektur.md)
- [06 – Roadmap](docs/06-roadmap.md)
- [SESSION-HANDOFF](docs/SESSION-HANDOFF.md) – aktueller Stand für Iterations-Übergabe
- [ADR (Architecture Decision Records)](docs/adr/) – große Architektur-Entscheidungen

Operativ:
- [DEPLOYMENT.md](DEPLOYMENT.md) – Production-Deployment
- [CONTRIBUTING.md](CONTRIBUTING.md) – Entwicklungs-Setup, Beitrag-Prozess

---

## Wichtiger Hinweis zum Verfahren

Diese Software bildet das **Verfahren** des Salzburger Lese-Screenings (SLS) digital ab.
Sie liefert **keine Original-Sätze, keine Original-Normtabellen und keine Original-Testhefte** mit.
Das Original-SLS ist ein kommerzielles Verfahren des [Hogrefe-Verlags](https://www.testzentrale.de/shop/salzburger-lese-screening-fuer-die-schulstufen-2-9.html).
Schulen, die diese Software einsetzen, müssen die zu nutzenden Materialien selbst lizenzieren bzw. eigene Materialien beschaffen und in das Portal einspielen.

---

## Lokale Entwicklung

### Voraussetzungen
- Docker Desktop / Docker Engine + Compose v2
- Optional: PHP 8.3+, Composer 2 (nur für Out-of-Container-Tooling)

### Erststart

```bash
# 1. Konfiguration vorbereiten
cp .env.example .env

# 2. Container bauen und starten
docker compose up -d --build

# 3. Laravel installieren (im Container)
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# 4. Setup-Wizard öffnen
# → http://localhost:8080/setup
```

Im Setup-Wizard:
1. Admin-Konto anlegen
2. Schulnamen eintragen
3. Klarnamen-Passwort vergeben
4. **Recovery-Key sichern** (wird nur einmalig angezeigt)

### Stack

| Container | Image | Zweck |
|-----------|-------|-------|
| `web` | caddy:2 | Reverse Proxy + TLS |
| `app` | custom (PHP 8.3-FPM + Laravel + Filament) | Anwendung |
| `queue` | custom | Queue-Worker (PDF, Mail, Backup) |
| `scheduler` | custom | Cron-Scheduler |
| `db` | mariadb:11 | Datenbank |
| `cache` | redis:7-alpine | Cache + Queue |
| `pdf` | gotenberg/gotenberg:8 | HTML→PDF |
| `backup` | custom | Backup-Worker |

---

## Beitragen

Details siehe [CONTRIBUTING.md](CONTRIBUTING.md). Kurzform:
- Issue eröffnen vor größeren Änderungen
- `composer lint` muss grün durchlaufen (Pint + PHPStan Level 5 mit Baseline)
- Neue Features brauchen Tests; lokal: `php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium vendor/bin/phpunit --no-coverage`

---

## Sicherheit

Sicherheitslücken bitte **nicht** als öffentliches Issue melden, sondern privat an den Maintainer.

---

## Lizenz

Die Software steht unter der [European Union Public Licence v1.2](LICENSE).
Sie darf frei kopiert, verändert und weiterverbreitet werden – auch von und für andere Schulen.
