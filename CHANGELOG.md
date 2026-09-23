# Changelog

Alle nennenswerten Änderungen in diesem Projekt sind hier dokumentiert. Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [1.48.0] – 2026-09-23

### Added
- **Animierte Vorführung „So funktioniert der Test“** auf der Hinweis-Seite (HTML/CSS/JS, kein Video): Eule mit Sprechblasen, Tipp-Finger, drei erfundene Beispielsätze (nicht aus den Fragebögen). Zeigt Antworten, automatisches Weiterblättern, **Zurückscrollen und Korrigieren** einer Antwort sowie Restzeit/Fortschritt. „Überspringen“/„Nochmal ansehen“, Textalternative, bei `prefers-reduced-motion` statisch.
- **Standard-Hinweistext** „Standard-Hinweis Lesetest“ als Bestand (`DefaultNoticeTextSeeder`, idempotent). Der als Standard markierte Hinweistext wird bei neuen Testdurchläufen vorausgewählt und im Schüler-Test angezeigt, wenn ein Durchlauf keinen eigenen hat. Es gibt höchstens einen Standard.

### Fixed
- **Geteiltes Tablet**: Ein anderer QR-Code löst eine noch offene Schüler-Sitzung ab, statt in den Test des vorherigen Schülers zu führen. Derselbe Code führt weiter zurück in den laufenden Test (Antworten und Restzeit bleiben erhalten).
- Wiederanmeldung in denselben Versuch setzt die Übungszeit nicht mehr zurück.
- Anmeldung mit einem bereits abgegebenen Code zeigt „Dein Test wurde bereits abgegeben“ statt einer allgemeinen Fehlermeldung.

### Upgrade
- `php artisan migrate` (`notice_texts.created_by_user_id` wird nullable), danach auf bestehenden Installationen `php artisan db:seed --class=DefaultNoticeTextSeeder`.

## [1.47.0] – 2026-09-22

Erster Release seit dem Live-Deployment: Datenanalyse, Durchführung am iPad, Testdurchlauf-Übersicht, echtes Login-2FA, Import-Verlauf und Standard-Bestände. Enthält auch die als 1.46.2 vorgesehenen Live-Hotfixes (dafür wurde nie ein Tag gesetzt).

### Added
- **Auswertung → Datenanalyse**: Erhebungsdaten filtern (Schuljahr, Erhebungstyp, Testdurchläufe, Jahrgang, Lerngruppen, Geschlecht, Wiederholer, Parallelform, Zeitraum) und in sechs Ansichten auswerten – **Vergleich** (mehrzeiliger LQ-Boxplot je Gruppe mit Kennzahlentabelle, auch zweistufig wie „Klassen × Geschlecht“), **Förderbereiche** (gestapelte Balken), **Entwicklung** (Median + Q1–Q3 je Erhebungswelle, Δ-LQ je Schüler, stärkste Verschlechterungen nach der aktiven Δ-Schwelle), **Verteilung vs. Norm** (Histogramm mit Normkurve, beobachtete vs. erwartete Anteile), **Tempo & Genauigkeit** (bearbeitete Sätze gegen Fehlerquote) und **Satzanalyse** (Lösungsquote, übersprungen, „erreicht“ je Satz). Schnellwahl, Filter und Ansicht in der URL, Klick auf eine Gruppe öffnet die Schülerliste, **gespeicherte Auswertungen** (privat oder freigegeben). Export als **A4-PDF** (Ansichten wählbar, optional mit Klarnamen, Fußzeile „Vertraulich“, abgelegt unter „Erzeugte Dokumente“) und **CSV**; beide Exporte im Audit-Log. Lehrkräfte sehen nur zugewiesene Lerngruppen, Schulleitung/Admin (`analytics.school_overview`) die ganze Schule. Beim Geschlechtervergleich weist die Seite darauf hin, dass der LQ geschlechtsspezifisch normiert ist.
- **Übersicht je Testdurchlauf** (`/admin/test-runs/{id}/uebersicht`): Live-Status alle 10 s, Code/QR anzeigen, Karte nachdrucken, Versuch zurücksetzen/beenden, Code sperren, Versuchsverlauf; Kennzahlen und LQ-Boxplot (optional nach Geschlecht bzw. mit Förderbereichen), scope-gefiltert; Reset respektiert „Lehrkraft darf Reset“.
- **Dashboard**: eine Kachel je aktivem Testdurchlauf (fertig/gesamt, laufen gerade, nicht angemeldet, Ø LQ), Klick öffnet die Übersicht; Kacheln verlinken auf Listen bzw. das vorgefilterte Audit-Log.
- **Login-Karten als QR-Kartenblatt (PDF)**: A4-Blatt mit ausschneidbaren Karten je Schüler (Klasse, Name, Code, persönlicher QR-Code auf den Test mit vorbefülltem Code), identisches 2×5-Raster auf jeder Seite für das Schneidgerät. Aufruf per QR meldet ohne Tippen an.
- **Schüler-Durchführung**: Vorabübung wird wirklich durchgeführt (eigene Übungsfragen, Countdown, sofortige Rückmeldung); der Haupttest-Timer startet erst bei „Test starten“; Fokus-Ansicht mit einer Aussage pro Bildschirm, Restzeit, Fortschritt und „Automatisch weiter“ (iPad quer: drei Aussagen). `/` ist jetzt die Code-Anmeldung mit Link zu `/admin/login`.
- **Automatische Wertung**: Versuche mit abgelaufener Zeit, die der Browser nie abgegeben hat, wertet der Scheduler minütlich (`attempts:finalize-expired`).
- **Echtes Login-2FA**: Wer 2FA aktiviert hat, bestätigt nach dem Passwort einen TOTP-/Recovery-Code, bevor das Panel nutzbar ist (`EnforceLoginTwoFactor` + `LoginTwoFactor`-Challenge, Nachweis pro Session, Rate-Limit). Vorher diente 2FA nur als Step-up für sensible Aktionen.
- **Login per Username oder E-Mail** (E-Mail-Format → Spalte `email`, sonst `username`).
- **Schüler-Import mit Fortschrittsanzeige** (chunk-weiser Commit per `wire:poll`, damit die Klarnamen-Verschlüsselung in der Session bleibt) und dauerhafter **Import-Verlauf** (Datum, Datei, Nutzer, Statistik, archivierte SuS und Fehler je Lauf).
- **Standard-Bestände für Neuinstallationen**: je eine Druckvorlage pro Template-Typ (`DefaultPrintTemplatesSeeder`) und das Rückmeldeset „SLS-Standardrückmeldung (LQ)“ mit drei an die Förderschwellen angepassten LQ-Bändern (`DefaultFeedbackSetsSeeder`). Beide idempotent, bestehende (auch bearbeitete) Einträge bleiben unangetastet.

### Changed
- Zeilen-Aktionen der Testdurchlauf-Liste als Dropdown (kein horizontales Scrollen mehr); Aktion „Login-Codes drucken (PDF)“.
- Druckvorlagen-Renderer stellt Array-Variablen (`rows`, `students`, `history`, `stats`) als HTML-Tabellen dar statt als JSON.
- Panel nutzt eine eigene Login-Page; ein frischer Login setzt das 2FA-Gate zurück, frisch eingerichtetes 2FA gilt für die laufende Session als bestätigt.
- Antworten nach Zeitablauf (plus 5 s Kulanz) werden abgelehnt; ein zurückgesetzter Versuch beendet die Schüler-Sitzung.
- LQ-Statistik (Quantile, Whisker, Förderbereiche) liegt in `App\Domain\Analytics\DistributionStats`; Übersicht und Datenanalyse nutzen dieselbe SVG-Boxplot-Komponente. `GotenbergClient::htmlToPdf()` nimmt Zusatzdateien an (z. B. `footer.html`).
- `print_template_versions.created_by_user_id` und `feedback_sets.created_by_user_id` sind nullable (system-gesäte Einträge haben keinen menschlichen Ersteller).
- Typisierung: Relationen mit generischen Rückgabetypen, `ScopeFilter` generisch – drei PHPStan-Baseline-Einträge entfallen. Tests laufen mit `memory_limit=512M`.

### Fixed
- **Scheduler lief nie** (`schedule:run`): Die Umleitung `>> /dev/stdout` scheiterte als User `lsp` an den Rechten – keine Backups, keine Audit-Archivierung, kein Aufräumen.
- **Leerer APP_KEY im Config-Cache**: queue/scheduler (User `lsp`) konnten die root-600-`.env` nicht lesen und cachten einen leeren Key in den geteilten `bootstrap/cache` → Klarnamen-Krypto brach nach Neustarts. Der Entrypoint gibt `.env` jetzt der Gruppe `lsp` lesbar (nicht world-readable) und cacht nur mit verfügbarem Key.
- **docker-compose**: queue/scheduler hatten ein unvollständiges `environment` und überschrieben den gemeinsamen Config-Cache mit falschen Defaults (Rückfall auf sqlite); `&app_env` angeglichen.
- **Schüler-Import „Datei nicht gefunden“**: Upload wird über die `local`-Disk gelesen (gleicher Bug wie v1.46.1 bei den Normtabellen).
- **500 auf Lerngruppen / Schüler / Testläufe**: `modifyQueryUsing`-Closures hießen `$q` statt `$query` (Filament injiziert per Name).
- **500 in „Erzeugte Dokumente“**: Closure-Parameter muss `$state` heißen.
- Import-Commit ohne Rückmeldung bei abgelaufener Klarnamen-Re-Auth (jetzt persistente Meldung; gesperrte Klarnamen als Banner direkt über dem Import-Button; überflüssiges Bestätigungs-Modal entfernt, das den Start verhinderte).
- Schüler-Test auf iPad/iPhone: kein Zurückspringen auf bereits gelöste Aussagen beim Auto-Weiter und kein Hängenbleiben nach einer Antwortänderung (WebKit-Scroll-Snap durch skriptgesteuertes Einrasten ersetzt).

### Betrieb / Upgrade
- `php artisan migrate --force` (neu u. a.: `test_attempts.main_started_at`, Import-Fortschritt, `analysis_presets`, nullable Ersteller-Spalten).
- **Image neu bauen** (der Entrypoint steckt im Image): `docker compose build app && docker compose up -d`. Erst dann setzt der neue Entrypoint die `.env`-Rechte; danach mit `docker compose logs scheduler` prüfen, dass der Scheduler läuft.
- Bestehende Installationen: Standard-Vorlagen/-Rückmeldeset bei Bedarf mit `php artisan db:seed --class=DefaultPrintTemplatesSeeder` bzw. `--class=DefaultFeedbackSetsSeeder` ergänzen (idempotent).

## [1.46.1] – 2026-09-15

### Fixed
- **Normtabellen-CSV-Import importierte nie Zeilen**: Die Datei wurde unter `storage/app/…` gesucht, die `local`-Disk liegt aber unter `storage/app/private` → stets „0 Norm-Zeilen importiert" ohne Fehler. Jetzt Zugriff über die Disk; der Upload wird danach gelöscht (landete sonst in den Backups)
- Normtabellen-Import: BOM/Windows-1252 (Excel), Trennzeichen `;` `,` oder Tab, zeilengenaue Fehlermeldungen, alles-oder-nichts in einer Transaktion; Fehlermeldung statt Erfolg, wenn nichts importiert wurde

## [1.46.0] – 2026-09-14

Produktionsreife für den Betrieb auf einer Docker-VM hinter Nginx Proxy Manager.

### Fixed
- **Backup auf MariaDB lief nie**: Tabellenliste kam aus `sqlite_master`; jetzt treiberunabhängig über den Schema-Builder. Binärspalten (verschlüsselte Klarnamen, DEK-Wraps, 2FA-Secrets) werden im JSON-Manifest base64-markiert und beim Restore verlustfrei zurückgeschrieben
- **Backup-Passwort aus dem UI wurde verworfen** (nicht fillable) → Backups waren unverschlüsselt (`NOENC`). Create/Edit setzen es jetzt korrekt; Backup-Runs ohne Passwort schlagen fehl statt Klartext zu schreiben
- `backup:run` liefert Exit-Code ≠ 0, wenn ein Ziel fehlschlägt
- **Schüler-Rate-Limits sperrten ganze Klassen hinter einer Schul-NAT-IP aus**: Login jetzt pro Code (10/min) plus großzügig pro IP (300/min), Antworten pro laufendem Versuch (120/min); alle Werte per `LSP_STUDENT_*` konfigurierbar
- **Antworten gingen bei 429/Netzfehlern still verloren**: Schüler-UI wiederholt fehlgeschlagene Saves automatisch, markiert ungespeicherte Zeilen und speichert vor Abgabe/Timeout alles

### Added
- SFTP-Backup-Ziel: Upload jedes Backups inkl. Größenprüfung, Retention auch extern, „Verbindung testen"-Action, Passwort- oder Schlüssel-Auth, optionaler Host-Fingerprint; Zugangsdaten verschlüsselt (`encrypted:array`, Migration für Bestandsdaten)
- Tägliches `backup:run` im Scheduler (`LSP_BACKUP_TIME`, Default 02:30)
- **Manueller Fragen-Import per UI (CSV/JSON)**: neuer Fragebogen aus Datei oder Fragen in bestehenden Fragebogen anhängen/ersetzen, Test- und Übungsfragen, Excel-Encoding, Zeilen-genaue Fehlermeldungen, alles-oder-nichts, Audit-Log, CSV-/JSON-Vorlagen
- Reverse-Proxy-Betrieb: `TRUSTED_PROXIES` (Laravel), `LSP_CADDY_TRUSTED_PROXIES` (Caddy), https-Links bei `APP_URL=https://…`
- `infra/Caddyfile.tls` für Betrieb ohne externen Proxy, `.env.production.example`, NPM-Anleitung in DEPLOYMENT.md
- Redis startet mit `requirepass`, wenn `REDIS_PASSWORD` gesetzt ist; Gotenberg-Healthcheck

### Changed
- **Breaking (Dev):** `docker-compose.override.yml` ist nicht mehr eingecheckt (öffnete DB/Redis/Gotenberg-Ports und erzwang Debug auch auf Produktions-Hosts). Lokal: `cp docker-compose.override.example.yml docker-compose.override.yml`
- **Breaking (Betrieb):** `infra/Caddyfile` lauscht standardmäßig nur HTTP auf `:80` (hinter Proxy). Standalone-TLS über `LSP_CADDYFILE=Caddyfile.tls`
- **Breaking (Betrieb):** Bestehende Backup-Ziele ohne Backup-Passwort müssen im UI ein Passwort erhalten, sonst schlagen Backups fehl
- Compose-Defaults: `APP_ENV=production`, `APP_DEBUG=false`; Platzhalter-Container `backup` entfernt

### Docs
- DEPLOYMENT: Abschnitt „Deploy-Variante: Portainer (Git-Repository-Stack)" — Anlegen via Repository-Quelle mit Tag-Pin, Update via „Pull and redeploy", Warnsignal bei extern erzeugten Stacks
- DEPLOYMENT-Troubleshooting: Caddyfile-Mount-Fehler („not a directory") inkl. Fix-Sequenz (`rm -rf` defektes Auto-Verzeichnis → `git checkout` → `docker rm -f` + `up -d`)

## [1.45.0] – 2026-05-01

### Fixed
- Docker-Stack-Erststart läuft ohne manuelle Workarounds: PHP-Container auf 8.4 angehoben, MariaDB-Index-Name in `questionnaire_practice_questions` gekürzt, Caddy-Pfad-Mismatch zu PHP-FPM behoben, `auto_https disable_redirects` für lokale Setups, named volumes durch Bind-Mounts ersetzt
- Storage-/bootstrap-cache-Permissions: Container startet als root, Entrypoint legt Verzeichnisse an + chown, PHP-FPM-Master bleibt root (Pool-Config switcht Worker zu `lsp`), andere Commands droppen via `gosu`
- `notifications`-Tabelle fehlte (Filaments `databaseNotifications()` crashte) — Standard-Migration nachgereicht
- `config_encrypted`-Spalten in `import_sources` + `backup_targets` von `json` auf `text` (MariaDB-`JSON_VALID`-Constraint lehnte Cipher-Strings ab)

### Added
- README + DEPLOYMENT mit Reset-Befehlen, PHP-8.4-Hinweis
- Filament-Assets werden vom Entrypoint automatisch publiziert wenn fehlend

## [1.44.0] – 2026-04-30

### Added
- `lsp:selftest`-Command (DB / Cache / Queue / Mail / Storage / Crypto / Gotenberg / AppSetting). JSON-Output via `--json`, Exit-Code = Anzahl Failures.

## [1.43.0] – 2026-04-30

### Added
- CI-Pipeline (`.github/workflows/ci.yml`): Pint + PHPStan + PHPUnit auf jeden Push/PR gegen `main`

## [1.42.0] – 2026-04-30

### Added
- Bulk-Reset für aktive Login-Codes eines TestRuns (`TestEngine::regenerateActiveLoginCodes` + Filament-Action)

## [1.41.0] – 2026-04-30

### Added
- DEPLOYMENT.md (Production-Setup, Cron-Jobs, Restore, Sicherheits-Checkliste)
- CONTRIBUTING.md (lokales Setup, Test-Befehle, Code-Standards)
- 5 ADRs in `docs/adr/`: Envelope-Encryption, Permission-Modell, Importer-Adapter, Backup-Format, DSGVO-Audit-Lifecycle

## [1.40.0] – 2026-04-30

### Added
- Sicherheits-Header-Middleware (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy)
- Rate-Limit auf öffentlichen Schüler-Test-Routes (10/min Login, 120/min Antwort)

## [1.39.0] – 2026-04-30

### Changed
- Code-Quality-Pass: Pint auto-fix auf ~93 Files, PHPStan Level 5 mit Larastan-Baseline

## [1.38.0] – 2026-04-30

### Added
- Schema-Drift-Erkennung im BackupRestorer (Spalten-Diff im Plan)

### Changed
- Backup-Tests via `Storage::fake()` (kein manuelles Cleanup mehr)

## [1.37.0] – 2026-04-30

### Added
- `backup:restore --snapshot-before` (Pre-Restore-Notfall-Snapshot)

## [1.36.0] – 2026-04-30

### Added
- E2E-Smoke-Tests (Dusk) für Filament-Admin: Login, User-Liste, Auth-Redirect

## [1.35.0] – 2026-04-30

### Added
- Backup inkludiert Storage-Files (`lsp/imports`, `lsp/print-jobs`, `lsp/exports`); Restore schreibt zurück. Größenlimit, Selbst-Inklusion-Schutz für `lsp/backups`.

## [1.34.0] – 2026-04-30

### Changed
- Importer-Refactor: gemeinsame `AbstractStudentImporter`-Basisklasse (~350 Zeilen Duplikat eliminiert, Public-API unverändert)

## [1.33.0] – 2026-04-30

### Added
- Audit-Logging für Wrap-Provisioning + Revoke
- `audit:purge`-Command für Hard-Delete archivierter Einträge (Default 730 Tage, wöchentlicher Cron)
- SekI-Stufenfilter im SVWS-Importer (Default 5–10), Wizard-Toggle

## [1.32.0] – 2026-04-30

### Added
- Recovery-Key-Verwaltung im Filament-UI: Status-Übersicht + Regenerate-Action mit einmaliger Klartext-Anzeige
- `CryptoService::regenerateRecoveryKey()` mit Audit-Trail

## [1.31.0] – 2026-04-30

### Added
- `BackupRestorer`-Service mit echter DB-Wiederherstellung (Schema-Check, FK-Off, TRUNCATE+INSERT, SQLite + MariaDB)
- CLI `backup:restore` mit `--dry-run`, `--force`, `--allow-version-mismatch`
- Permission `system.backup.restore`

## [1.30.0] – 2026-04-30

### Added
- 2FA-Pflicht pro UserGroup erzwingbar via `EnforceTwoFactorIfRequired`-Middleware + `ForceTwoFactorSetup`-Page

### Changed
- `sendWelcome`-Single-Action mit explizitem `users.manage`-Permission-Gate

## [1.29.0] – 2026-04-30

### Added
- SVWS-NRW-API-Importer aktiv: `SvwsApiClient`, `SvwsApiImporter`, `ImporterFactory`, `ImportSourceResource`. Live verifiziert gegen echte SVWS-Instanz.

## [1.28.0] – 2026-04-30

### Added
- Audit-Log-Soft-Archivierung (`archived_at`, `audit:archive`-Cron, Filament-Filter, Default 90 Tage)

## [1.27.0] – 2026-04-30

### Added
- Klarnamen-Zugang für andere User: Admin-Provisioning + Revoke, Status-Spalte, Permissions `clearname.password.provision/revoke`

## [1.26.0] – 2026-04-30

### Added
- Bulk-Welcome-Mail-Action für User-Resource

## [1.25.0] – 2026-04-30

### Added
- E2E-Browser-Tests (Laravel Dusk) für Schüler-Test-Flow inkl. Timer-Ablauf, ChromeDriver-Setup-Doku

## [1.21.0] – [1.24.0] – 2026-04

### Added
- Onboarding-Flow mit Welcome-Mail + erzwungenem Passwortwechsel
- TeacherStats-Widget
- Re-Calc-Tool für Normtabellen
- `lsp:demo-data`-Command

## [1.0.0] – 2026-07-01

Erstes stabiles Release. Funktionsumfang gemäß [docs/01-pflichtenheft.md](docs/01-pflichtenheft.md), implementiert in fünf Phasen.

### Domain

- Längsschnitt-Lesediagnostik (SLS-Verfahren) für Klassen 5–10 (bis 8 Jahre)
- Persistente Schüler-Identität, Match über SchiLD-/SVWS-ID
- Mehrere Erhebungen pro Schuljahr/Lerngruppe mit konfigurierbarem Typ-Label
- Persistente Speicherung von Rohwert + LQ pro Erhebung
- LQ nachträglich neu berechenbar bei Norm-Tabellen-Änderung (mit Historie)
- Konfigurierbare Förderbedarf-Schwellen (LQ absolut, Δ-LQ, Median-Vergleich)
- Persönliches Verlaufsdiagramm pro Schüler
- Aggregierte Auswertungen: Schule/Jahrgang/Klasse mit Trend (besser/schlechter)
- Archiv mit Altersfilter + selektive Löschung (kein Auto-Delete)

### Sicherheit & Datenschutz

- Single-Tenant pro Schule
- **Envelope Encryption** für Klarnamen (Argon2id-KEK + AES-256-GCM)
  mit mehreren parallelen User-Passwörtern und einem Recovery-Key
- Granulare Permissions, frei anlegbare Benutzerklassen, Vererbung mit Override
- Lerngruppen-Scope für Lehrkräfte (Default: alles, sobald Scope gesetzt nur diese)
- Optional: 2FA (TOTP) mit Recovery-Codes
- Audit-Log für sicherheitsrelevante Aktionen
- DSGVO-Workflows: Auskunfts-Export, manuelle Löschung mit 4-Augen-Prinzip

### Technik

- Laravel 12, PHP 8.3, Filament 3
- MariaDB 11, Redis 7, Gotenberg 8 (PDF), Caddy 2 (TLS)
- Komplettes Docker-Compose-Setup
- Sanctum-Auth, Spatie-Permission/Activitylog/Backup
- Bacon-QR-Code, maatwebsite/excel, dedoc/scramble (OpenAPI)
- HTML+CSS-Druckvorlagen mit Versionierung
- SMTP-Mailversand mit Anhang + Mailprotokoll
- Backup verschlüsselt (AES-256-GCM), Cron+manuell, Restore-CLI
- Importer-Architektur mit Adapter-Pattern (SchildCsv aktiv, SvwsApi vorbereitet)
- Importassistent mit Validate → Diff → Archivkandidaten → Commit
- Lizenz: EUPL 1.2

### Tests

64 Tests / 247 Assertions

## [0.5.0-phase4] – 2026-06-15

Phase 4: Mail & Backup. Siehe Tag-Beschreibung.

## [0.4.0-phase3] – 2026-06-01

Phase 3: Auswertung & PDF. Siehe Tag-Beschreibung.

## [0.3.0-phase2] – 2026-05-15

Phase 2: Test-Engine. Siehe Tag-Beschreibung.

## [0.2.0-phase1] – 2026-05-01

Phase 1: Stammdaten & Import. Siehe Tag-Beschreibung.

## [0.1.0-phase0] – 2026-04-29

Phase 0: Fundament. Siehe Tag-Beschreibung.
