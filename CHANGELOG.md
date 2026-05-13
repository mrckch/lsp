# Changelog

Alle nennenswerten Änderungen in diesem Projekt sind hier dokumentiert. Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.46.0] – 2026-05-12

### Added
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
