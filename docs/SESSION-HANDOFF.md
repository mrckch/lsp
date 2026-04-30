# Session-Handoff für die nächste Claude-Session

Dieses Dokument fasst den Stand des Projekts so zusammen, dass eine neue Session ohne Vorwissen produktiv weitermachen kann. Bitte nach jeder größeren Iteration aktualisieren.

---

## Projekt

**LSP – Lese-Screening-Portal** · Open-Source-Webanwendung für digitale Lesediagnostik an Schulen (SLS-Verfahren, Klassen 5–10).

- **Pfad:** `C:\Coding\LSP-Docker-APP`
- **Lizenz:** EUPL 1.2
- **Sprache der Doku & UI:** Deutsch
- **Stack:** Laravel 12 · Filament 3 · MariaDB · Redis · Gotenberg · Docker Compose · Caddy
- **Aktueller Stand:** Tag **`v1.44.0`** · **279 PHPUnit-Tests / 964 Assertions** + **10 Dusk-E2E-Tests / 36 Assertions** durchgehend grün · `composer lint` (Pint + PHPStan Level 5) sauber · CI-Pipeline (GitHub Actions) auf jeden Push gegen `main` · `lsp:selftest`-Command für End-to-End-Diagnose

---

## Vor jeder Aktion

1. **Doku lesen** – die Spezifikation lebt in `docs/01-pflichtenheft.md` bis `docs/06-roadmap.md`. Architektur-Entscheidungen sind dort begründet.
2. **Stil:** kompakt, pragmatisch, keine Over-Engineering-Schleifen, keine spekulativen Features. Domain-getrennt unter `app/Domain/<Bereich>/`.
3. **Sprache:** Code-Kommentare und User-sichtbare Strings auf Deutsch.

---

## Repo-Struktur (Highlights)

```
app/
  Console/Commands/   – CLI: Backup, Cleanup, DemoData, PrivacyDelete
  Domain/
    Analytics/        – AnalyticsService, SupportListCsvExporter
    Attempt/          – TestEngine, TestAttempt-Models
    Audit/            – AuditLogger, AuditLog
    Auth/             – TwoFactorService, OnboardingService
    Backup/           – BackupRunner
    Crypto/           – CryptoService (Envelope Encryption)
    FeedbackSet/      – FeedbackSet, FeedbackSetRange
    Import/           – Adapter-Pattern + SchildCsvImporter
    Mail/             – MailService, Models
    NormTable/        – LqResolver, LqRecalculationService
    NoticeText/, Permission/, Privacy/, Questionnaire/, School/,
    Student/, SupportThreshold/, TestRun/, PrintJob/, PrintTemplate/
    Setup/            – SetupService
  Filament/
    Concerns/         – AuthorizedResource, AuthorizedPage,
                        HandlesPrintErrors
    Pages/            – ImportWizard, AuditLog, MailSettings,
                        ClearnameUnlock/Change, TwoFactorSetup,
                        StudentHistoryChart, SupportListPage,
                        ForcePasswordChange
    Resources/        – ~14 Resources (alle perm-gated)
    Widgets/          – PdfServiceHealth, MyJobsStatus, AuditStats,
                        TeacherStats
  Http/
    Controllers/      – SetupController, StudentTestController
    Middleware/       – RequireSetupCompleted, EnsurePermission,
                        EnsureRecentTwoFactor, EnforcePasswordChange
  Jobs/
    Concerns/LogsFailureToAudit
    GenerateBulkFeedbackZipJob, GenerateBulkHistoryZipJob,
    SendBulkFeedbackMailJob, MailSupportListJob, SendWelcomeMailJob
  Models/             – User, AppSetting

database/
  migrations/         – chronologisch, eine pro logischer Änderung
  seeders/            – PermissionCatalog, DefaultUserGroups,
                        DefaultAssessmentTypes, DefaultSupportThresholds,
                        DefaultPrintTemplates

resources/views/
  setup/, student-test/, emails/welcome.blade.php,
  filament/pages/, filament/widgets/, filament/resources/

tests/Feature/        – Domain- und Filament-Tests, alle grün
tests/Browser/        – Laravel-Dusk-E2E-Tests (StudentTestFlowTest, StudentTimerTest)
tests/DuskTestCase.php – Basis für E2E-Tests, säubert Cookies in tearDown
docs/                 – 01..07 Spezifikation + dieses Handoff
infra/                – Dockerfile, Caddyfile, docker-compose.yml
```

---

## Was funktioniert (Stand v1.24.0)

**Auth & Sicherheit**
- Setup-Wizard mit DEK + Recovery-Key
- Envelope-Encryption für Klarnamen, Multi-User-Wraps, jährliche Rotation
- Granulare Permissions, Klassen, Scopes, User-Overrides
- 2FA optional, Re-Auth-Pflicht für sensitive Aktionen
- Onboarding-Flow: Welcome-Mail mit Initial-Passwort + erzwungener Wechsel

**Stammdaten & Import**
- Schuljahre, Lerngruppen, persistente Schüler (über Schullaufbahn stabil)
- SchiLD-CSV-Importassistent mit Diff/Archivkandidaten
- SVWS-API-Adapter als Stub vorbereitet

**Test-Engine**
- Fragebögen mit Parallelformen + Übungsfragen
- Normtabellen 3D (Stufe × Form × Geschlecht)
- TestRuns mit Login-Codes, Schüler-UI mit JS-Timer
- LQ-Berechnung mit Snapshot + Re-Berechnung (History)
- Re-Calc-Tool im UI

**Auswertung**
- Schüler-Detailseite mit Mini-Verlauf
- Schüler-Verlaufsdiagramm (Chart.js, mit Schwellenlinien)
- Förderbedarfs-Liste mit Filter, PDF/CSV/Mail-Export
- TeacherStats-Widget (eigene Klassen/SuS/Tests/Ø LQ)
- AuditStats-Widget (Klarnamen-Bewegung heute/7d)

**Drucksachen**
- HTML+CSS-Templates mit Versionierung
- TipTap-Editor + Variablen-Helper + PDF-Vorschau
- Gotenberg-Anbindung, Health-Check, Exception-Wrapping
- Bulk-Operationen alle in Queue (PDF + Mail) → Erzeugte-Dokumente-Hub

**Operations**
- Backup verschlüsselt (AES-256-GCM/Argon2id) + Restore-CLI
- Audit-Log durchsuchbar, JSON-Auskunft, DSGVO-Lösch-Workflow mit 4-Augen
- Cleanup-Cron für abgelaufene Dokumente
- Failed-Jobs im User-Widget mit Fehlertext
- `php artisan lsp:demo-data` für Spielwiese

---

## Tag-Historie (in v1.x)

| Tag | Inhalt |
|-----|--------|
| v0.1–v0.5 | Phasen 0–4 (Fundament, Stammdaten, Test-Engine, Auswertung+PDF, Mail+Backup) |
| v1.0.0 | Erstes stabiles Release |
| v1.1–v1.5 | Filament-UI, Druckvorlagen-Editor, Permission-Guards, Verlaufsdiagramm, Bulk-Rückmeldungen |
| v1.6.0 | Scope-Härtung + TestRun-Ownership |
| v1.7–v1.8 | Gotenberg-Entkopplung, Schüler-Detailseite |
| v1.9–v1.12 | Bulk-Verlauf, Förderliste, Bulk-PDF-Queue, Privacy-Workflow |
| v1.13–v1.15 | Bulk-Mail-Queue, Job-Status-Widget, Verlauf-Queue |
| v1.16–v1.20 | Failed-Jobs, Cleanup-Cron, Mail-Action-Förder, Audit-Stats, CSV-Export |
| v1.21–v1.24 | Onboarding, TeacherStats, Re-Calc-UI, Demo-Data |
| v1.25.0 | E2E-Browser-Tests mit Laravel Dusk (Schüler-Test-Flow) |
| v1.26.0 | Bulk-Welcome-Mail-Action für User (mit Permission-Gate) |
| v1.27.0 | Klarnamen-Zugang für andere User: Admin-Provisioning + Revoke + UI |
| v1.28.0 | Audit-Log Soft-Archivierung (Cron + Filter, Default 90 Tage) |
| v1.29.0 | SVWS-NRW-API-Importer aktiv (mit ImportSource-UI, Live gegen echte Instanz verifiziert) |
| v1.30.0 | 2FA-Pflicht pro Klasse erzwingen + sendWelcome-Permission-Konsistenz |
| v1.31.0 | Backup-Restore mit echter DB-Wiederherstellung (CLI mit Dry-Run + Confirmation) |
| v1.32.0 | Recovery-Key-Verwaltung im UI (Status-Übersicht + Regenerate) |
| v1.33.0 | 3 Folgepunkte: Wrap-Audit-Log, Audit-Hard-Delete-Cron, SVWS-SekI-Filter |
| v1.34.0 | Importer-Refactor: AbstractStudentImporter (gemeinsame Diff/Commit-Logik) |
| v1.35.0 | Backup inkludiert Storage-Files (Imports/Print-Jobs/Exports), Restore schreibt sie zurück |
| v1.36.0 | E2E-Smoke-Test für Filament-Admin (Login + List + Auth-Redirect) |
| v1.37.0 | Backup-Restore Pre-Snapshot (`--snapshot-before` Belt-and-Braces) |
| v1.38.0 | Backup-Tests via Storage::fake + Schema-Drift-Erkennung im Restorer |
| v1.39.0 | Code-Quality-Pass: Pint auto-fix (~93 Files) + PHPStan Level 5 sauber (Baseline) |
| v1.40.0 | Sicherheit: CSP/X-Frame-Headers + Rate-Limit auf Schüler-Test-Routes |
| v1.41.0 | OSS-Doku: README aktualisiert + DEPLOYMENT + CONTRIBUTING + 5 ADRs |
| v1.42.0 | Bulk-Reset-Login-Codes (rotiert aktive Codes eines TestRuns) |
| v1.43.0 | CI-Pipeline (GitHub Actions): Lint + PHPUnit auf Push/PR gegen main |
| **v1.44.0** | **`lsp:selftest`-Command (DB/Cache/Queue/Mail/Storage/Crypto/Gotenberg/AppSetting)** |

---

## Offene Punkte / Backlog

Vom User explizit als „später" markiert oder am Ende der letzten Session vorgeschlagen, aber nicht angegangen:

1. **Dusk-Test für Bulk-Action-Modal-Flow** — der eigentliche Bulk-Action-Klick (Multi-Row-Auswahl + Modal-Confirmation) ist in v1.36.0 bewusst weggelassen worden, weil Dusk + Livewire-Modals fragil. Wenn das gewünscht ist, müsste man Page-Objects in Filament-Style aufbauen.

---

## Lokale Entwicklung

```bash
# Tests laufen lassen (PHP lokal mit nötigen Extensions)
php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium \
    vendor/bin/phpunit --no-coverage

# E2E-Browser-Tests (Laravel Dusk) — benötigen laufenden Dev-Server:
#   1. Dev-Server in separater Shell starten:
#        php artisan serve --env=dusk.local --port=8000 --host=127.0.0.1
#   2. In anderer Shell:
#        php artisan dusk --no-coverage
# Voraussetzung: ChromeDriver in vendor/laravel/dusk/bin/chromedriver-win.exe
# (passend zur installierten Chrome-Version, Download via Chrome-for-Testing-API)

# Stack starten
docker compose up -d
docker compose exec app php artisan migrate --seed

# Im Browser: http://localhost:8080/setup
# Dann: php artisan lsp:demo-data --clearname-password=... --students=30
```

---

## Konventionen, die ich gelernt habe

- **Pro Iteration ein Tag** (`vX.Y.0`) mit Commit-Message in der etablierten Form: Titel-Zeile + Bulletpoints + `Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>` am Ende.
- Bei jedem Schritt **Tests schreiben**, vor dem Tag: alles muss grün sein.
- Filament-Resources brauchen die `AuthorizedResource`-Trait + 4 Permission-Methoden.
- Filament-Pages brauchen `AuthorizedPage` + `requiredPermission()`.
- PDF-Aktionen über `HandlesPrintErrors::runPrintAction()` für saubere Notification.
- Bulk-Operationen → Queue-Job mit `LogsFailureToAudit`-Trait.
- Tests für Filament-ViewRecord-Pages: `record`-Property via Closure-Hack setzen (siehe `ViewStudentTest::makePage()`).
- `EncryptedName`-Cast braucht entsperrte Klarnamen-Session zum Schreiben → Tests müssen `CryptoService::initialize()` und ggf. `unlock()` rufen.
