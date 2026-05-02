# Production-Deployment

Single-Tenant pro Schule: eine Installation = eine Schule. Empfohlenes Setup ist Docker Compose mit
Reverse-Proxy (Caddy übernimmt TLS), MariaDB, Redis, Gotenberg für PDF.

## Voraussetzungen

- Linux-Host mit Docker Engine + Compose v2 (≥ 1 GB RAM, ≥ 10 GB Disk)
- Domain mit DNS auf den Host (für Caddy-Auto-TLS via Let's Encrypt)
- SMTP-Zugang für E-Mail-Versand (Welcome-Mails, Bulk-Mails)

## Erstinstallation

```bash
# 1. Repo klonen
git clone <repo-url> lsp
cd lsp

# 2. Konfiguration
cp .env.example .env
# .env editieren: APP_URL, LSP_HOSTNAME, LETSENCRYPT_EMAIL,
# DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD, MAIL_*

# 3. Stack bauen + starten (PHP 8.4-FPM, MariaDB 11, Redis 7, Caddy 2, Gotenberg 8)
docker compose up -d --build

# 4. Laravel-Initialisierung
docker compose exec app composer install --no-dev --optimize-autoloader --no-scripts
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force --seed
docker compose exec app php artisan lsp:selftest    # Diagnose: alles grün?

# 5. Setup-Wizard im Browser öffnen
# Lokal/Dev: http://localhost:8080/setup
# Production: https://<deine-domain>/setup
```

**Wenn schon vorhanden, `composer.lock` muss zum Container-PHP passen.**
Der Container nutzt PHP 8.4 (siehe `infra/app/Dockerfile`). Falls du lokal eine
andere PHP-Version hast und `vendor/` dort generiert wurde, kann der Container-
Start fehlschlagen. Lösung: `vendor/` löschen oder `composer install` im
Container ausführen.

## Reset („alles von vorne")

```bash
docker compose down -v        # Stoppt alles + löscht DB-/Cache-Volumes
docker compose up -d --build  # Frischer Start
docker compose exec app php artisan migrate --seed --force
# → http://localhost:8080/setup wieder von vorne
```

Im Setup-Wizard:
1. Admin-Konto anlegen (Username + Passwort + optional E-Mail)
2. Schulnamen + Kurzname (für Print-Footer) eintragen
3. **Klarnamen-Passwort** vergeben — verschlüsselt die DEK, die Schülernamen schützt
4. **Recovery-Key SICHERN** — wird nur einmalig angezeigt, ohne ihn ist bei verlorenem Klarnamen-Passwort kein Zugriff mehr möglich

## Wichtige Konfiguration

### `.env` (auszugsweise)

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lsp.beispiel-schule.de

DB_CONNECTION=mysql
DB_HOST=db
DB_DATABASE=lsp
DB_USERNAME=lsp
DB_PASSWORD=<langes-zufalls-passwort>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_PASSWORD=<langes-zufalls-passwort>

# Caddy: TLS-Zertifikat-Email
LETSENCRYPT_EMAIL=admin@beispiel-schule.de

# Mail
MAIL_MAILER=smtp
MAIL_HOST=mail.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=lsp@beispiel-schule.de
MAIL_PASSWORD=<smtp-passwort>
MAIL_FROM_ADDRESS=lsp@beispiel-schule.de
MAIL_FROM_NAME="LSP – Beispiel-Schule"

# Audit-Lifecycle
LSP_AUDIT_ARCHIVE_AFTER_DAYS=90
LSP_AUDIT_PURGE_AFTER_DAYS=730
```

### Backup-Ziel anlegen

Nach dem Setup-Wizard im Admin-UI unter **System → Backup-Ziele**:
- Typ: `local` (in `storage/lsp/backups/` im Container) oder `sftp`/`s3` für externes Ziel
- Backup-Passwort (Argon2id-Wrap der Daten) — separat vom Recovery-Key, ebenso sicher verwahren!
- Retention: `daily=7 / weekly=4 / monthly=12` als Default

Cron läuft via `php artisan schedule:run` — der Compose-Service `scheduler` triggert das jede Minute.

### Standard-Cron-Jobs (siehe `routes/console.php`)

| Aktion | Wann |
|---|---|
| `documents:cleanup` | täglich 03:15 — abgelaufene generierte PDFs löschen |
| `audit:archive` | täglich 03:30 — Audit-Einträge älter als 90 d → soft-archive |
| `audit:purge` | sonntags 03:45 — archivierte ältere als 2 J → hard-delete |

Backup-Run muss zusätzlich manuell oder per externem Cron getriggert werden:
```bash
docker compose exec app php artisan backup:run
```

## Sicherheits-Checkliste

- [x] **TLS via Caddy**: automatisches Let's-Encrypt-Cert
- [x] **2FA-Pflicht für Admin-Klasse**: per Default-Seeder gesetzt
- [x] **Rate-Limit auf Schüler-Login**: 10 Versuche/Min/IP
- [x] **CSP/X-Frame/Referrer-Policy** auf allen Antworten
- [ ] **HSTS** auf Caddy-Ebene konfigurieren (siehe `infra/Caddyfile`)
- [ ] **Backup-Ziel extern** (SFTP/S3) — local-only ist nicht DR-tauglich
- [ ] **Recovery-Key + Backup-Passwort** physisch sicher verwahren (Tresor / Passwort-Manager)

## Restore aus Backup

```bash
# Plan + Confirmation interaktiv
docker compose exec app php artisan backup:restore lsp_backup_20260901_031500_run42.bin

# Oder: dry-run zur Validierung
docker compose exec app php artisan backup:restore <file> --dry-run

# Oder: mit Pre-Snapshot-Sicherung vor TRUNCATE (Belt-and-Braces)
docker compose exec app php artisan backup:restore <file> --snapshot-before --force
```

Restore ist **zerstörerisch** — alle Tabellen aus dem Backup werden TRUNCATEd, dann
neu eingespielt. Tabellen, die im Backup nicht enthalten sind, bleiben unangetastet.
`migrations` wird IMMER übersprungen (Schema-Drift-Schutz).

## Update auf neuen Release-Tag

**Production-VMs sollten auf einem fixierten Tag stehen, nie auf `main`.** Das
verhindert, dass ein versehentlicher `git pull` halbfertige Änderungen ins
Production-System zieht.

```bash
cd /opt/lsp

# 1. Vor Update: Backup ziehen!
docker compose exec app php artisan backup:run

# 2. Neuen Tag holen
git fetch --tags
git checkout v1.46.0     # konkrete Version, nicht 'main'

# 3. Container + Dependencies aktualisieren
docker compose up -d --build
docker compose exec app composer install --no-dev --optimize-autoloader --no-scripts
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose restart app queue scheduler

# 4. Diagnose
docker compose exec app php artisan lsp:selftest
```

### Rollback bei Problemen

```bash
# Zurück auf den vorherigen Tag
git checkout v1.45.0
docker compose up -d --build

# Wenn auch DB-Schema rückwärts nötig: aus dem Pre-Update-Backup wiederherstellen
docker compose exec app php artisan backup:restore <pre-update-backup.bin> --snapshot-before
```

### Hotfix-Updates (Patch-Releases)

Hotfixes werden als Patch-Tag (`v1.45.1` statt `v1.46.0`) vom letzten Production-
Tag abgezweigt. Update-Befehl ist identisch — `git checkout v1.45.1`.

## Monitoring / Health

- `GET /up` — Laravel-Health-Endpoint (kein Auth nötig)
- Failed-Jobs sichtbar im Admin → User-Dashboard-Widget
- PdfServiceHealth-Widget zeigt Gotenberg-Status

## Troubleshooting

**Port 443 oder 80 belegt** (z. B. anderer Server auf der Host-Maschine): Caddy
bindet auf 8080/8443 (HTTP/HTTPS) per Default — siehe `LSP_HTTP_PORT` /
`LSP_HTTPS_PORT` in der `.env`. Lokale Dev-URL ist dann `http://localhost:8080/setup`.

**Setup-Wizard zeigt sich nicht**: prüfen ob `is_initialized` in `app_settings` evtl. schon true ist.

**Schüler-Test rendert nicht**: Browser-Console prüfen — vermutlich Asset-Pfad falsch (APP_URL nicht passend).

**PDF-Erzeugung schlägt fehl**: Gotenberg-Container-Health checken (`docker compose logs pdf`),
URL in `config/lsp.php` (`pdf.gotenberg_url`) muss aus Sicht des `app`-Containers erreichbar sein.

**Klarnamen-Session sperrt sich nach Inaktivität**: das ist Absicht. Per User in den Filament-
Pages → Klarnamen → Entsperren. Sensitive Aktionen brauchen 2FA-Re-Auth (Default 15 min,
config `lsp.two_factor.reauth_ttl_minutes`).
