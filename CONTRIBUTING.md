# Beitragen

Beiträge sind willkommen — sowohl Bug-Fixes als auch Feature-Vorschläge. Bitte ein Issue
eröffnen, bevor größere Änderungen begonnen werden, damit die Richtung abgestimmt werden kann.

## Lokales Setup

### Voraussetzungen

- PHP 8.3+ (mit Extensions: pdo_sqlite, sqlite3, sodium, openssl, mbstring, intl)
- Composer 2
- Optional: Docker Compose v2 für vollen Stack

### Erster Lauf (ohne Docker, schnellste Variante für Code-Iteration)

```bash
composer install
cp .env.example .env
# .env kürzen auf: APP_ENV=local, DB_CONNECTION=sqlite, etc.
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
# → http://localhost:8000/setup
```

### Voller Stack (Docker)

Siehe [DEPLOYMENT.md](DEPLOYMENT.md) — `docker compose up -d --build`.

## Tests laufen lassen

PHPUnit (Unit + Feature):
```bash
php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium \
    vendor/bin/phpunit --no-coverage
```
Aktueller Stand: 271 Tests / 947 Assertions, alle grün.

Dusk (E2E-Browser):
```bash
# In Shell 1:
php artisan serve --env=dusk.local --port=8000 --host=127.0.0.1

# In Shell 2:
php artisan dusk --no-coverage
```
Voraussetzungen: ChromeDriver in `vendor/laravel/dusk/bin/chromedriver-win.exe` (oder Linux-Pendant),
passend zur installierten Chrome-Version. `.env.dusk.local` aus `.env.dusk.example` kopieren.

## Code-Standards

- **PHP-Style**: Pint (Laravel-Preset). `composer fix` zum Auto-Format.
- **Statische Analyse**: PHPStan Level 5 mit Larastan + Baseline (`phpstan-baseline.neon`).
  Neue Findings müssen behoben oder explizit in Baseline aufgenommen werden.
- **`composer lint`** muss vor jedem Commit grün sein:
  ```bash
  composer lint   # ruft pint --test + phpstan analyse auf
  ```
- **Strict Types**: jede neue PHP-Datei beginnt mit `declare(strict_types=1);`.
- **Sprache**: Code-Kommentare und User-sichtbare Strings auf Deutsch
  (Single-Tenant pro deutscher Schule).

## Architektur-Konventionen

- **Domain-getrennt**: Geschäftslogik unter `app/Domain/<Bereich>/` (Crypto, Audit, Import, …),
  nicht in Controllers oder Models.
- **Filament-Resources**: jede Resource nutzt `AuthorizedResource`-Trait + die 4 Permission-Methoden.
- **Filament-Pages**: nutzen `AuthorizedPage` + `requiredPermission()`.
- **Bulk-Operationen**: immer als Queue-Job mit `LogsFailureToAudit`-Trait.
- **PDF-Aktionen**: über `HandlesPrintErrors::runPrintAction()` für saubere Notifications bei
  Gotenberg-Ausfällen.
- **Sensitive Aktionen**: brauchen `EnsureRecentTwoFactor`-Middleware.

## Branch-Strategie

GitHub Flow + Tag-basierte Releases. Drei Regeln:

1. **`main` ist heilig** — alles was hier landet, ist getestet + lint-clean
   (CI muss grün sein; Branch-Schutz ist auf GitHub aktiviert).
2. **Jede Änderung in einem Feature-Branch** — kein direkter Push auf `main`.
3. **Production-VMs checken nur Tags aus**, nie `main` direkt.

### Branch-Namen-Konvention

| Präfix | Wofür | Beispiele |
|---|---|---|
| `feat/` | Neue Funktionalität | `feat/svws-cron-import`, `feat/eltern-ergebnisbrief` |
| `fix/` | Bug-Fix | `fix/notifications-migration`, `fix/csp-filament` |
| `chore/` | Wartung, Dependency-Update, Doku | `chore/upgrade-laravel-12.6`, `chore/changelog-update` |
| `docs/` | Nur Dokumentation | `docs/adr-mysqldump`, `docs/branching-strategy` |
| `hotfix/` | Production-Notfall (vom letzten Production-Tag aus) | `hotfix/clearname-decrypt-crash` |

### Standard-Workflow (Feature)

```bash
git checkout main
git pull
git checkout -b feat/<beschreibung>
# ... Code-Änderungen, Commits ...
git push -u origin feat/<beschreibung>
# Auf GitHub: Pull Request öffnen → CI läuft automatisch
# Nach grünem CI + Review: "Squash and merge" nach main
git checkout main && git pull
git branch -d feat/<beschreibung>     # lokal aufräumen
```

Nach Merge — wenn Iteration release-würdig — neuer Tag:

```bash
git tag -a v1.46.0 -m "Iteration X: Kurzbeschreibung"
git push origin v1.46.0
```

### Hotfix-Workflow

Wenn auf Production-VM was crasht und `main` aber schon weiter ist:

```bash
git checkout v1.45.0       # vom letzten Production-Tag aus
git checkout -b hotfix/<beschreibung>
# ... Fix + Tests ...
git push -u origin hotfix/<beschreibung>
# PR → Merge nach main
git checkout main && git pull
git tag -a v1.45.1 -m "Hotfix: ..."   # PATCH-Tag, nicht MINOR!
git push origin v1.45.1
```

Auf der Production-VM:
```bash
cd /opt/lsp
git fetch --tags
git checkout v1.45.1
docker compose up -d --build
docker compose exec app php artisan migrate --force
```

## Commit-Konventionen

Pro Feature-/Fix-Iteration ein Tag (`vX.Y.Z`). Commit-Message-Form siehe `git log`:
```
type(scope): Kurztitel-Zeile (Imperativ, prägnant)

Optional: Kontext + Begründung.

- Bullet-Liste der Änderungen
- inkl. Test-Counts vorher/nachher

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

`type` wie im Branch-Präfix (`feat`, `fix`, `chore`, `docs`, `hotfix`); `scope`
optional, z. B. `feat(svws): cron-basierter Auto-Import`.

## Sicherheitslücken

**Bitte NICHT** als öffentliches Issue melden, sondern per E-Mail an den Maintainer
(siehe Repo-Settings). Wir nehmen Hinweise ernst und reagieren zeitnah.

## Lizenz

Mit Beitrag stimmst du zu, dass dein Code unter der [EUPL 1.2](LICENSE) veröffentlicht wird —
gleichwertig kompatibel mit AGPL/GPL für Re-Use durch andere Schulen.
