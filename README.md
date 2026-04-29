# LSP – Lese-Screening-Portal

> Open-Source-Webanwendung für die digitale Durchführung, Auswertung und Längsschnitt-Beobachtung von Lese-Screenings (nach dem Verfahren des Salzburger Lese-Screenings) an Schulen in Deutschland.

**Lizenz:** [EUPL 1.2](LICENSE)
**Sprache:** Deutsch
**Status:** in Entwicklung – Phase 0 (Fundament)

---

## Dokumentation

Die vollständige Spezifikation liegt im [`docs/`](docs/)-Verzeichnis:

- [01 – Pflichtenheft](docs/01-pflichtenheft.md)
- [02 – Datenmodell](docs/02-datenmodell.md)
- [03 – Berechtigungskatalog](docs/03-permissions.md)
- [04 – API-Spec (OpenAPI 3.1)](docs/04-api-spec.yaml)
- [05 – Stack & Architektur](docs/05-stack-architektur.md)
- [06 – Roadmap](docs/06-roadmap.md)

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

Beiträge willkommen. Bitte ein Issue eröffnen, bevor größere Änderungen vorgenommen werden.

Code-Standards:
- PHP: PSR-12, geprüft via Pint
- Statische Analyse: PHPStan Level 6+
- Tests: PHPUnit + Pest, neue Features brauchen Tests

---

## Sicherheit

Sicherheitslücken bitte **nicht** als öffentliches Issue melden, sondern privat an den Maintainer.

---

## Lizenz

Die Software steht unter der [European Union Public Licence v1.2](LICENSE).
Sie darf frei kopiert, verändert und weiterverbreitet werden – auch von und für andere Schulen.
