# Production-Deployment

Single-Tenant pro Schule: eine Installation = eine Schule. Empfohlenes Setup ist Docker Compose auf
einer Linux-VM mit MariaDB, Redis und Gotenberg für PDF. TLS übernimmt entweder ein **externer
Reverse-Proxy** (z. B. Nginx Proxy Manager auf eigener VM — Standard) oder der mitgelieferte Caddy selbst.

## Voraussetzungen

- Linux-VM mit Docker Engine + Compose v2 (empfohlen 2 vCPU / 4 GB RAM / 40 GB Disk für ~1000 SuS)
- Domain mit DNS auf den Reverse-Proxy (bzw. direkt auf die VM im Caddy-TLS-Modus)
- SMTP-Zugang für E-Mail-Versand (Welcome-Mails, Bulk-Mails)
- Externer SFTP-Server für Backups (NAS, zweite VM, Hoster)

## Erstinstallation

```bash
# 1. Repo auf festen Release-Tag klonen (nie 'main' in Produktion)
git clone <repo-url> /opt/lsp
cd /opt/lsp
git checkout v1.46.1

# 2. Konfiguration
cp .env.production.example .env
# .env editieren: alle <…>-Platzhalter ersetzen (APP_URL, DB-/Redis-Passwörter,
# IP der NPM-VM, MAIL_*). Passwörter z. B. mit: openssl rand -base64 32

# .env für den Container-User lsp (uid/gid 1000) lesbar machen — sonst cachen
# die queue-/scheduler-Container einen leeren APP_KEY (siehe Hinweis unten).
sudo chgrp 1000 .env && chmod 640 .env

# 3. Stack bauen + starten (PHP 8.4-FPM, MariaDB 11, Redis 7, Caddy 2, Gotenberg 8)
docker compose up -d --build

# 4. Laravel-Initialisierung
docker compose exec app composer install --no-dev --optimize-autoloader --no-scripts
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force --seed
docker compose restart app queue scheduler   # Config-Cache mit APP_KEY neu aufbauen
docker compose exec app php artisan lsp:selftest    # Diagnose: alles grün?

# 5. Setup-Wizard im Browser öffnen: https://<deine-domain>/setup
```

> **Keine `docker-compose.override.yml` auf Produktions-Hosts.** Die Datei wird von Compose automatisch
> geladen. Die Vorlage `docker-compose.override.example.yml` ist nur für lokale Entwicklung (Debug an,
> DB-/Redis-Ports offen).

**`composer.lock` muss zum Container-PHP passen.** Der Container nutzt PHP 8.4 (siehe
`infra/app/Dockerfile`). Wurde `vendor/` lokal mit einer anderen PHP-Version erzeugt, kann der
Container-Start fehlschlagen. Lösung: `vendor/` löschen oder `composer install` im Container ausführen.

> **Warum `.env` für uid/gid 1000 lesbar sein muss.** `app` (PHP-FPM) läuft als root, aber die
> `queue`- und `scheduler`-Container droppen im Entrypoint via `gosu` auf den User `lsp` (uid/gid 1000).
> Bei `APP_ENV=production` baut jeder Container beim Start `config:cache` — im geteilten,
> bind-gemounteten `bootstrap/cache`. Kann `lsp` die `.env` nicht lesen (z. B. `root:root 600`, wie es
> bei `openssl`-generierten Secrets unter restriktiver umask leicht entsteht), cacht er einen **leeren
> `APP_KEY`** und überschreibt damit den korrekten. Folge: `lsp:selftest` meldet *„crypto: No application
> encryption key has been specified"* und die Klarnamen-Entschlüsselung bricht — reproduzierbar nach
> jedem `docker compose restart app queue scheduler`. Der Entrypoint härtet das seit v1.46.2 zusätzlich
> ab (setzt Gruppen-Leserecht selbst und cacht nie mehr einen leeren Key), aber ein `chmod 640` mit
> Gruppe `1000` auf dem Host ist der saubere, explizite Weg. `640` statt `644`, damit die Secrets **nicht**
> world-readable werden.

## Deploy-Variante: Portainer (Git-Repository-Stack)

Wenn der Host mit Portainer verwaltet wird, sollte der Stack **als Git-Repository-Stack
in Portainer angelegt** werden — nicht per SSH + `docker compose up` an Portainer vorbei.
So bleibt Portainer Single-Source-of-Truth, Updates laufen über „Pull and redeploy",
und der ganze Repo-Stand (inkl. `infra/Caddyfile`) ist garantiert auf dem Host
ausgecheckt.

**Stack anlegen:**
1. Portainer → **Stacks → Add stack → Repository**
2. Repository-URL: `<repo-url>`
3. Reference name: konkreter Tag (`refs/tags/v1.46.1`), **nicht** `refs/heads/main`
4. Compose path: `docker-compose.yml`
5. Environment variables aus `.env.production.example` übernehmen und produktive Werte setzen
   (`APP_URL`, `DB_PASSWORD`, `REDIS_PASSWORD`, `LSP_CADDY_TRUSTED_PROXIES`, `TRUSTED_PROXIES`, …)
6. **Deploy the stack**

Danach einmalig die Laravel-Init aus „Erstinstallation" Schritt 4 im `app`-Container
ausführen (`composer install … --no-scripts`, `key:generate`, `migrate --seed`).

> **`.env` in Portainer-Stacks:** Legt der Stack seine `.env` als Datei im Working-Dir an
> (`/data/compose/<id>/.env`), gilt dieselbe Regel wie oben — sie muss für uid/gid 1000 lesbar
> sein (`chgrp 1000 .env && chmod 640 .env` per SSH auf dem Host). Werden die Werte dagegen als
> Portainer-*Environment variables* gesetzt und `APP_KEY` explizit übergeben, reicht das dem
> Entrypoint bereits (er cacht dann aus der Umgebung).

**Update auf neuen Tag:**
1. Portainer → Stack → **Editor**
2. Reference name auf neuen Tag setzen (`refs/tags/v1.47.0`)
3. **Pull and redeploy** anklicken
4. Im `app`-Container: `composer install …`, `migrate --force`, `config:cache`,
   `route:cache` (siehe „Update auf neuen Release-Tag")

**Warnsignal:** Wenn auf der Stack-Seite *„This stack was created outside of
Portainer. Control over this stack is limited."* steht, wurde der Stack per CLI
gestartet — Portainer kann ihn nicht sauber redeployen. In dem Fall: Stack einmal
komplett killen (`docker compose down` per SSH) und neu als Repository-Stack über
Portainer anlegen.

Im Setup-Wizard:
1. Admin-Konto anlegen (Username + Passwort + optional E-Mail)
2. Schulnamen + Kurzname (für Print-Footer) eintragen
3. **Klarnamen-Passwort** vergeben — verschlüsselt die DEK, die Schülernamen schützt
4. **Recovery-Key SICHERN** — wird nur einmalig angezeigt, ohne ihn ist bei verlorenem Klarnamen-Passwort kein Zugriff mehr möglich

## Betrieb hinter Nginx Proxy Manager (eigene VM)

```
Browser ──https──▶ NPM-VM (TLS, Let's Encrypt) ──http──▶ LSP-VM:8080 (Caddy) ──fastcgi──▶ app (PHP-FPM)
```

### `.env` auf der LSP-VM

```ini
APP_URL=https://lsp.deine-schule.de
SESSION_SECURE_COOKIE=true
LSP_CADDYFILE=Caddyfile              # Caddy nur HTTP auf :80 (im Container)
LSP_HTTP_PORT=8080                   # Port auf der LSP-VM, den NPM anspricht
LSP_CADDY_TRUSTED_PROXIES=10.0.0.5/32  # IP der NPM-VM
TRUSTED_PROXIES=*                    # Laravel übernimmt X-Forwarded-* (von Caddy durchgereicht)
```

Warum beides: Caddy übernimmt `X-Forwarded-For`/`-Proto` nur von der NPM-VM und überschreibt sie bei
allen anderen Absendern. Laravel liest daraus die echte Schüler-IP (Rate-Limits, Audit-Log) und erkennt
`https` (korrekte Links, sichere Cookies). Ohne diese Einstellungen landen alle Anfragen unter der
NPM-IP im selben Rate-Limit-Topf.

### Proxy Host in NPM

| Feld | Wert |
|---|---|
| Domain Names | `lsp.deine-schule.de` |
| Scheme / Forward Hostname / Port | `http` / IP der LSP-VM / `8080` |
| Block Common Exploits | an |
| Websockets Support | nicht nötig |
| SSL | Let's-Encrypt-Zertifikat, **Force SSL**, HTTP/2, HSTS an |

Unter **Advanced → Custom Nginx Configuration** (Uploads bis 50 MB für Importe, Timeout für Bulk-PDFs):

```nginx
client_max_body_size 60m;
proxy_read_timeout 120s;
```

### Firewall auf der LSP-VM

Port 8080 darf nur von der NPM-VM erreichbar sein. **Achtung:** Docker veröffentlicht Ports an `ufw`
vorbei. Entweder `LSP_HTTP_BIND` auf die interne IP der LSP-VM setzen und das Netz absichern, oder
eine Regel in der `DOCKER-USER`-Chain anlegen:

```bash
iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport 8080 ! -s <ip-der-npm-vm> -j DROP
```

(Regel persistent machen, z. B. über `iptables-persistent`.)

### Alternative: ohne externen Proxy

Caddy holt selbst das Zertifikat: in der `.env` `LSP_CADDYFILE=Caddyfile.tls`, `LSP_HOSTNAME=<domain>`,
`LSP_HTTP_PORT=80`, `LSP_HTTPS_PORT=443`, `TRUSTED_PROXIES=` (leer) setzen.

## Wichtige Konfiguration

Vollständige Vorlage: [`.env.production.example`](.env.production.example). Auszug:

```ini
APP_ENV=production
APP_DEBUG=false
REDIS_PASSWORD=<langes-zufalls-passwort>   # Redis startet damit automatisch mit requirepass

# Schüler-Rate-Limits pro Minute — ganze Klassen teilen sich meist eine Schul-IP
LSP_STUDENT_LOGIN_PER_IP=300           # grober Deckel gegen Code-Raten
LSP_STUDENT_LOGIN_PER_CODE=10          # Fehlversuche pro Login-Code
LSP_STUDENT_ANSWERS_PER_ATTEMPT=120    # Antwort-Requests pro laufendem Test

# Audit-Lifecycle
LSP_AUDIT_ARCHIVE_AFTER_DAYS=90
LSP_AUDIT_PURGE_AFTER_DAYS=730
```

## Backups

Nach dem Setup-Wizard im Admin-UI unter **System → Backup-Ziele → Neu**:

- **Typ `SFTP`** (empfohlen): Host, Port, Benutzer, Zielverzeichnis und Passwort *oder* privater
  SSH-Schlüssel. Optional den Host-Fingerprint hinterlegen. Zugangsdaten werden verschlüsselt gespeichert.
- **Typ `Lokal`**: nur Kopie auf derselben VM — nicht DR-tauglich.
- **Backup-Passwort** (Pflicht, ≥ 12 Zeichen): Argon2id + AES-256-GCM. Separat vom Recovery-Key im
  Tresor verwahren — ohne dieses Passwort ist das Backup wertlos.
- **Retention**: `daily=7 / weekly=4 / monthly=12` als Default (lokal und extern angewandt)

Danach in der Liste **„Verbindung testen"** und einmal **„Jetzt sichern"** ausführen.

Jedes Backup wird verschlüsselt lokal unter `storage/app/private/lsp/backups/` abgelegt und bei
SFTP-Zielen zusätzlich hochgeladen. Schlägt der Upload fehl, ist der Run `failed` (lokale Kopie bleibt).
Der Scheduler startet `backup:run` täglich um `LSP_BACKUP_TIME` (Default 02:30). Manuell:

```bash
docker compose exec app php artisan backup:run   # Exit-Code ≠ 0, wenn ein Ziel fehlschlägt
```

### Standard-Cron-Jobs (siehe `routes/console.php`)

Der Compose-Service `scheduler` führt `php artisan schedule:run` jede Minute aus.

| Aktion | Wann |
|---|---|
| `backup:run` | täglich 02:30 (`LSP_BACKUP_TIME`) — alle aktiven Backup-Ziele |
| `documents:cleanup` | täglich 03:15 — abgelaufene generierte PDFs löschen |
| `audit:archive` | täglich 03:30 — Audit-Einträge älter als 90 d → soft-archive |
| `audit:purge` | sonntags 03:45 — archivierte ältere als 2 J → hard-delete |

## Fragebögen importieren

Unter **Test-Konfiguration → Fragebögen**:

- **„Importieren (CSV/JSON)"** legt aus einer Datei einen neuen Fragebogen (Status *Entwurf*) an.
- **„Fragen importieren"** auf der Bearbeiten-Seite hängt Fragen an oder ersetzt sie.
- **„Vorlagen"** liefert Beispieldateien.

CSV: Spalten `satz;antwort;typ` (Trennzeichen `;`, `,` oder Tab, Kopfzeile optional, UTF-8 oder
Excel/Windows-1252). `antwort` = `richtig`/`falsch` (auch `r`/`f`, `ja`/`nein`, `1`/`0`),
`typ` = `test` (Standard) oder `uebung`.

JSON:

```json
{
  "name": "Form A1",
  "parallel_form": "A1",
  "grade_level_target": "5-6",
  "default_time_limit_seconds": 180,
  "practice_time_seconds": 30,
  "practice_questions": [{ "text": "Die Sonne ist heiß.", "answer": "richtig" }],
  "questions": [{ "text": "Schnee ist schwarz.", "answer": "falsch" }]
}
```

Der Import ist alles-oder-nichts: bei einem fehlerhaften Eintrag wird nichts gespeichert, die Fehler
werden mit Zeilennummer angezeigt. Fragebögen, die bereits in Testdurchläufen verwendet wurden, lassen
sich nicht mehr per Import ändern (Vergleichbarkeit der Rohwerte) — dafür einen neuen Fragebogen importieren.

## Sicherheits-Checkliste

- [x] **TLS** am Reverse-Proxy (NPM) bzw. via Caddy-Auto-TLS
- [x] **HSTS**: in NPM aktivieren (Caddy setzt den Header zusätzlich)
- [x] **2FA-Pflicht für Admin-Klasse**: per Default-Seeder gesetzt
- [x] **Rate-Limit auf Schüler-Login**: pro Code + großzügig pro IP
- [x] **CSP/X-Frame/Referrer-Policy** auf allen Antworten
- [x] **Keine offenen DB-/Redis-/Gotenberg-Ports** (keine Override-Datei auf dem Host)
- [ ] **`REDIS_PASSWORD`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`** mit Zufallswerten gesetzt
- [ ] **App-Port nur für die NPM-VM** erreichbar (Firewall / `DOCKER-USER`)
- [ ] **Backup-Ziel extern** (SFTP) angelegt, Verbindungstest + erstes Backup erfolgreich
- [ ] **Restore einmal getestet** (z. B. auf einer Test-VM)
- [ ] **Recovery-Key + Backup-Passwort** physisch sicher verwahren (Tresor / Passwort-Manager)

## Restore aus Backup

Backup-Datei von SFTP holen und nach `storage/app/private/lsp/backups/` legen (sofern nicht mehr lokal vorhanden), dann:

```bash
# Plan + Confirmation interaktiv
docker compose exec app php artisan backup:restore lsp_backup_20260901_023000_run42.bin

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
git checkout v1.46.1     # konkrete Version, nicht 'main'

# 3. Container + Dependencies aktualisieren
docker compose up -d --build --remove-orphans
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
git checkout v1.46.0
docker compose up -d --build

# Wenn auch DB-Schema rückwärts nötig: aus dem Pre-Update-Backup wiederherstellen
docker compose exec app php artisan backup:restore <pre-update-backup.bin> --snapshot-before
```

### Hotfix-Updates (Patch-Releases)

Hotfixes werden als Patch-Tag (`v1.45.1` statt `v1.46.0`) vom letzten Production-
Tag abgezweigt. Update-Befehl ist identisch — `git checkout v1.45.1`.

## Reset („alles von vorne")

```bash
docker compose down -v        # Stoppt alles + löscht DB-/Cache-Volumes
docker compose up -d --build  # Frischer Start
docker compose exec app php artisan migrate --seed --force
# → https://<deine-domain>/setup wieder von vorne
```

## Monitoring / Health

- `GET /up` — Laravel-Health-Endpoint (kein Auth nötig), z. B. für Uptime-Kuma
- `backup:run` liefert Exit-Code ≠ 0 bei Fehlern; Backup-Runs mit Status/Fehlertext unter System → Backup-Ziele
- Failed-Jobs sichtbar im Admin → User-Dashboard-Widget
- PdfServiceHealth-Widget zeigt Gotenberg-Status

## Troubleshooting

**Port belegt**: Caddy bindet per Default auf 8080/8443 der VM — siehe `LSP_HTTP_PORT` /
`LSP_HTTPS_PORT` in der `.env`.

**Links/Assets werden als `http://` ausgeliefert oder Login-Schleife hinter NPM**: `APP_URL` mit
`https://`, `TRUSTED_PROXIES=*` und `LSP_CADDY_TRUSTED_PROXIES` (IP der NPM-VM) prüfen, danach
`docker compose restart app`.

**Schüler bekommen „Too Many Requests" (429)**: Stimmt die NPM-IP in `LSP_CADDY_TRUSTED_PROXIES`?
Sonst zählen alle Anfragen unter einer IP. Limits ggf. über `LSP_STUDENT_*` anpassen.

**`lsp:selftest` meldet „crypto: No application encryption key has been specified"** (obwohl `APP_KEY`
in der `.env` steht): Die `queue`-/`scheduler`-Container (User `lsp`, uid/gid 1000) konnten die `.env`
nicht lesen und haben einen leeren Key in den geteilten `bootstrap/cache` gecacht. Fix auf dem Host:

```bash
chgrp 1000 .env && chmod 640 .env
docker compose exec app php artisan config:clear
docker compose restart app queue scheduler
docker compose exec app php artisan lsp:selftest
```

Ab v1.46.2 verhindert der Entrypoint das doppelt (Gruppen-Leserecht + kein Cachen leerer Keys); auf
älteren Ständen ist der `chmod` oben die Lösung.

**Setup-Wizard zeigt sich nicht**: prüfen ob `is_initialized` in `app_settings` evtl. schon true ist.

**`web`-Container startet nicht, Fehler „error mounting … Caddyfile … not a directory"**:
Tritt auf, wenn beim ersten `docker compose up` der Pfad `infra/Caddyfile` auf dem
Host fehlte — Docker legt für fehlende Bind-Mount-Quellen automatisch ein leeres
**Verzeichnis** an, das danach kollidiert, weil der Container-Pfad eine Datei ist.
Fix auf dem Host (Stack-Working-Dir, bei Portainer-Stacks z. B. `/data/compose/<id>/`):

```bash
# Defektes Verzeichnis entfernen
rm -rf infra/Caddyfile
# Repo-Stand wiederherstellen
git checkout -- infra/Caddyfile      # oder: git pull (jetzt klappt der Checkout)
# Container neu erzeugen (restart reicht NICHT — Bind-Mount-Inode wird beim
# create gebunden)
docker rm -f <stack>-web-1
docker compose up -d web
```

Strukturell vermeidet das die Portainer-Git-Repository-Variante (siehe oben):
beim initialen Deploy ist das Caddyfile garantiert vorhanden.

**Schüler-Test rendert nicht**: Browser-Console prüfen — vermutlich Asset-Pfad falsch (APP_URL nicht passend).

**PDF-Erzeugung schlägt fehl**: Gotenberg-Container-Health checken (`docker compose ps`, `docker compose logs pdf`),
URL in `config/lsp.php` (`pdf.gotenberg_url`) muss aus Sicht des `app`-Containers erreichbar sein.

**Backup „Upload zum externen Ziel fehlgeschlagen"**: „Verbindung testen" im UI nutzen; Firewall der
SFTP-Gegenstelle, Schreibrechte im Zielverzeichnis und ggf. Host-Fingerprint prüfen.

**Klarnamen-Session sperrt sich nach Inaktivität**: das ist Absicht. Per User in den Filament-
Pages → Klarnamen → Entsperren. Sensitive Aktionen brauchen 2FA-Re-Auth (Default 15 min,
config `lsp.two_factor.reauth_ttl_minutes`).
