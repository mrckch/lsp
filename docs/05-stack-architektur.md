# LSP – Stack & Architektur

**Stand:** 2026-04-29

---

## 1. Überblick

```
                         ┌──────────────────────────────────┐
                         │   Browser (Lehrkraft / Admin)    │
                         │   • Filament-Admin               │
                         │   • Inertia/Vue für Schüler-Test │
                         └────────────────┬─────────────────┘
                                          │ HTTPS
                                          ▼
                                  ┌───────────────┐
                                  │     Caddy     │  ← Reverse Proxy + LE TLS
                                  └───────┬───────┘
                                          │
                                          ▼
                ┌─────────────────────────────────────────────┐
                │             app  (PHP-FPM, Laravel 12)       │
                │   ───────────────────────────────────────    │
                │   Filament-Panel  •  REST-API (Sanctum)      │
                │   Domain-Services • Permission/Scope-Layer   │
                │   Crypto (Envelope)  •  Importer-Adapters    │
                └──┬──────┬──────────┬──────────┬──────────────┘
                   │      │          │          │
                   ▼      ▼          ▼          ▼
              ┌────────┐ ┌─────┐ ┌────────┐ ┌──────────┐
              │MariaDB │ │Redis│ │Gotenberg│ │Mail (SMTP│
              │  11    │ │  7  │ │  PDF    │ │ extern)  │
              └────────┘ └─────┘ └────────┘ └──────────┘
                   ▲          ▲
                   │          │
              ┌────┴──────────┴────┐
              │ queue & scheduler  │  ← Worker-Container
              │ (Laravel artisan)  │
              └────────────────────┘
                   │
                   ▼
              ┌────────────┐         ┌──────────────────┐
              │  backup    │ ──SFTP─►│ externes Backup- │
              │ (Worker)   │         │ Ziel             │
              └────────────┘         └──────────────────┘
```

---

## 2. Backend-Module

Laravel-App nach Domain-Driven-Light strukturiert.

```
app/
  Domain/
    Auth/                      # Login, 2FA, Session
    Permission/                # Permissions, Scopes, Overrides
    Crypto/                    # DEK, Wraps, Recovery, Re-Wrap-Service
    School/                    # SchoolYear, LearningGroup
    Student/                   # Student, Enrollment, Membership
    Import/                    # Importer-Interface + Adapter
      Adapters/
        SchildCsvImporter.php
        SvwsApiImporter.php    # Phase 5
        ManualImporter.php
    Questionnaire/             # Questionnaire, Question, PracticeQuestion
    NormTable/                 # NormTable, Row, LqResolver
    FeedbackSet/
    NoticeText/
    AssessmentType/
    SupportThreshold/          # Schwellen-Definition + Evaluator
    TestRun/                   # TestRun, Security, Group
    Attempt/                   # Attempt, Answer, LqHistory
    Analytics/                 # CohortStats, TrendDiff, SupportListBuilder
    PrintTemplate/             # Templates, Versions
    PrintJob/                  # PdfRenderer, GotenbergClient
    Mail/                      # SmtpClient, MessageStore
    Backup/                    # BackupRunner, Encryption, SftpUploader
    Audit/                     # AuditLogger, Query

  Http/
    Controllers/
      Api/                     # REST-Controller, dünn
      StudentTest/             # Schüler-spezifische, Code-Login-basiert
    Middleware/
      EnsureClearnameUnlocked.php
      EnsureRecent2FA.php
      ApplyScope.php
    Requests/                  # FormRequests
    Resources/                 # API-Resources

  Filament/                    # Admin-Panel
    Resources/                 # CRUD pro Entität
    Pages/                     # Custom-Pages (Importassistent, Auswertung)
    Widgets/                   # Dashboard-Widgets

  Console/
    Commands/
      Setup/InitializeCommand.php
      Backup/RunCommand.php
      Backup/RestoreCommand.php
      Crypto/RotateClearnameCommand.php
      Norm/RecalculateLqCommand.php

  Providers/
    AppServiceProvider.php
    PermissionServiceProvider.php
    CryptoServiceProvider.php
    FilamentServiceProvider.php

config/
  lsp.php                       # zentrale Anwendungskonfig
  filament.php
  permission.php
  ...

database/
  migrations/
  seeders/
    PermissionCatalogSeeder.php
    DefaultUserGroupsSeeder.php
    DefaultAssessmentTypesSeeder.php
    DefaultSupportThresholdsSeeder.php

resources/
  views/
    student-test/             # Inertia/Blade Schüler-UI
    print-templates/          # System-Templates als HTML-Defaults
  js/
    student-test/             # Vue-Komponenten Schüler-Test
    admin-extensions/         # Filament-Custom-JS

tests/
  Feature/
  Unit/
  Domain/                     # Domain-Tests pro Modul
```

---

## 3. Schlüssel-Services (Domain)

### 3.1 Crypto / Envelope

```
CryptoService
  generateInitialDek(adminPassword, recoveryKey)  → DEK + initial wraps
  unlockSession(user, password)                    → DEK in Session
  lockSession()                                    → DEK aus Session entfernen
  changeUserPassword(user, oldPw, newPw)           → re-wrap nur dieser User-Wrap
  rotateDek()                                      → neue DEK + Re-Encryption-Job
  forceRotation()                                  → markiert alle User-Wraps als
                                                     "neu setzen erforderlich"
  recoverWithKey(recoveryKey, user, newPassword)   → Wrap für User neu erzeugen

EncryptedField (Eloquent-Cast)
  set(value)  → AES-256-GCM mit Session-DEK
  get(value)  → entschlüsselt; '***' wenn DEK nicht in Session
```

### 3.2 Permission

```
PermissionResolver
  effectivePermissions(user) → Set<string>
  can(user, key, ?context)   → bool

ScopeFilter
  applyTo(query, user, modelClass) → query mit Scope-WHERE
  // ein gemeinsames Konzept: jedes Modell deklariert in
  //   public function scopeOwningLearningGroupIds(): array
  // welche Lerngruppen es "berührt"

TwoFactorMiddleware
  prüft last_2fa_at gegen TTL (Default 15 min)
```

### 3.3 LQ-Berechnung

```
LqResolver
  resolve(rawScore, gradeLevel, gender, parallelForm) → ?LqValue
  bestNormTableFor(gradeLevel, parallelForm)

LqRecalculationService
  recalculateForAttempt(attempt) → updates lq_current + lq_history
  recalculateForRun(testRun)
  recalculateForNormTable(normTable)
```

### 3.4 Importer

```
interface StudentImporter
  validate(input): ValidationResult
  diff(input, schoolYearId): DiffSet     // create / update / archive / skip
  commit(diffSet, decisions): CommitResult

SchildCsvImporter implements StudentImporter
SvwsApiImporter   implements StudentImporter   // Phase 5
ManualImporter    implements StudentImporter
```

### 3.5 Print

```
PrintJobRunner
  run(job)
    1. Lade Template-Version (HTML+CSS)
    2. Render mit Twig/Blade + Variablen aus Context
    3. POST an Gotenberg → PDF-Bytes
    4. Speichere als generated_document
    5. Audit + Export-Log

GotenbergClient
  htmlToPdf(html, css, options): bytes
```

---

## 4. Frontend-Strategie

### 4.1 Admin / Lehrkraft / Sekretariat
- **Filament 3** als Hauptoberfläche
- Filament liefert: Tabellen, Filter, Forms, Wizards (für den Importassistenten), Dashboard-Widgets
- Spezialseiten als **Filament Pages** (z. B. Auswertungen mit Chart.js, Druckvorlagen-Editor mit TipTap)

### 4.2 Schüler-Test
- **Inertia.js + Vue 3** als eigenes Frontend (eine SPA-Insel)
- Begründung: Test-UI braucht spezielle UX (Timer, Tastatur-Shortcuts, Auto-Save, Vollbild) – nicht ideal in Filament abzubilden
- Komponenten:
  - `Login.vue` (Code-Eingabe, QR-Scan optional via Kamera)
  - `Practice.vue` (Übungsphase mit Mini-Timer)
  - `Test.vue` (Hauptphase: Frageliste, Antworten, Countdown)
  - `Result.vue` (Ergebnisanzeige nur mit LQ)

### 4.3 Druckvorlagen-Editor
- **TipTap** mit Custom-Nodes für Variablen-Chips und Diagramm-Blöcke
- Live-Preview-Pane: HTML wird im Browser gerendert (gleiche CSS wie das spätere PDF)

---

## 5. Docker-Compose

`docker-compose.yml` (Produktion + Dev mit Override-Datei).

```yaml
services:
  web:
    image: caddy:2
    ports: ["80:80","443:443"]
    volumes:
      - ./infra/Caddyfile:/etc/caddy/Caddyfile
      - caddy_data:/data
      - caddy_config:/config
      - public:/srv/public:ro
    depends_on: [app]

  app:
    build: ./infra/app
    environment:
      APP_ENV: production
      DB_HOST: db
      REDIS_HOST: cache
      GOTENBERG_URL: http://pdf:3000
    volumes:
      - storage:/var/www/html/storage
      - public:/var/www/html/public
    depends_on: [db, cache]

  queue:
    build: ./infra/app
    command: php artisan queue:work --queue=default,pdf,mail,backup
    environment: { ... gleiche wie app ... }
    volumes: [storage:/var/www/html/storage]
    depends_on: [app, cache]

  scheduler:
    build: ./infra/app
    command: ["sh","-c","while true; do php artisan schedule:run; sleep 60; done"]
    volumes: [storage:/var/www/html/storage]
    depends_on: [app]

  pdf:
    image: gotenberg/gotenberg:8
    command: ["gotenberg","--api-port=3000","--api-timeout=60s"]

  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: lsp
      MARIADB_USER: lsp
      MARIADB_PASSWORD_FILE: /run/secrets/db_password
      MARIADB_ROOT_PASSWORD_FILE: /run/secrets/db_root_password
    volumes:
      - db_data:/var/lib/mysql
    secrets: [db_password, db_root_password]

  cache:
    image: redis:7-alpine
    volumes: [cache_data:/data]

  backup:
    build: ./infra/app
    command: php artisan backup:run --watch
    volumes:
      - storage:/var/www/html/storage
      - backups:/backups
    depends_on: [app]

volumes:
  caddy_data:
  caddy_config:
  storage:
  public:
  db_data:
  cache_data:
  backups:

secrets:
  db_password: { file: ./secrets/db_password.txt }
  db_root_password: { file: ./secrets/db_root_password.txt }
```

`docker-compose.override.yml` (Dev): mountet Code-Verzeichnis, exposed 8080, deaktiviert TLS.

---

## 6. Verzeichnisstruktur (Repo-Wurzel)

```
LSP-Docker-APP/
  app/                       # Laravel-Source
  bootstrap/
  config/
  database/
  public/
  resources/
  routes/
  storage/
  tests/
  docs/                      # diese Dokumentation
  infra/
    app/
      Dockerfile             # PHP 8.3 + extensions + composer install
    Caddyfile
    seeds/
      default-templates/     # HTML-Default-Druckvorlagen
  scripts/
    setup.sh                 # Erst-Setup-Helfer
    backup-restore.sh
  docker-compose.yml
  docker-compose.override.yml
  .env.example
  composer.json
  package.json
  README.md
  LICENSE                    # EUPL 1.2
  CHANGELOG.md
```

---

## 7. Konfiguration & Secrets

- `.env` für lokale Defaults
- Sensible Werte (DB-Passwort, App-Key, SMTP-Pass) als **Docker Secrets** in Prod
- Verschlüsselte Werte in DB (SMTP-Pass, Backup-Pass, SVWS-Token) per Laravel-Encryption (`APP_KEY`)
- DEK-Wraps: völlig getrennt vom `APP_KEY` (Envelope Encryption mit User-/Recovery-KEKs)

---

## 8. Logging & Observability

- **Laravel-Log** → JSON-Lines, stdout der Container → Hetzner-Journal
- **Audit-Log** → DB-Tabelle, im Admin durchsuchbar
- **Mail-Log** → DB-Tabelle, im Admin durchsuchbar
- **Backup-Log** → DB-Tabelle + zusätzlich als JSON in Backup-Volumen
- **Health-Endpoint** `/api/v1/health` (DB, Redis, Gotenberg, freier Speicher) – im Admin sichtbar

---

## 9. Build & Deployment

### 9.1 Lokal (Dev)
```
git clone …
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan key:generate
# Setup-Wizard in Browser: http://localhost:8080/setup
```

### 9.2 Produktion (Hetzner CX22)
- Image-Bau via GitHub Actions, Push in GitHub Container Registry (ghcr.io)
- Auf Server: `docker compose pull && docker compose up -d`
- Migrationen idempotent als Init-Step im `app`-Entrypoint (oder als separater Job vor Deploy)

### 9.3 CI
GitHub Actions:
- `phpstan` + `pint` (Lint)
- `phpunit` (Tests)
- Docker-Image-Build
- Bei Tag `v*`: Release-Image pushen

---

## 10. Sicherheits-Hardening (Defaults)

- Caddy: HSTS, sichere TLS-Ciphers, automatische Redirects HTTP→HTTPS
- Laravel: `secure_cookies`, `same_site=lax`, CSRF überall, Rate-Limit pro IP für Login
- 2FA-Re-Auth-Middleware für ⚠-Permissions
- DB: nur intern erreichbar (kein gemapptes Port)
- Backup-Volumen: nur durch `backup`-Container schreibbar
- Schüler-Endpunkte: separater Rate-Limit-Bucket, Bot-Detection (Honeypot, Throttle pro Code)
