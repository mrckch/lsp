# LSP – Roadmap

**Stand:** 2026-04-29

Inkrementelle Lieferung in sechs Phasen. Jede Phase endet mit einem **lauffähigen, getesteten Stand**. Phasen-Übergänge sind die natürlichen Punkte für Review und Anpassung.

---

## Phase 0 – Fundament (Wochen 1–2)

**Ziel:** Lauffähige Entwicklungsumgebung, Auth-Basis, Klarnamen-Krypto, Setup-Wizard.

### Lieferungen
- Repo-Struktur, `docker-compose.yml`, `infra/app/Dockerfile`
- Caddy + Laravel + MariaDB + Redis + Gotenberg laufen lokal
- Laravel-Skelett mit Filament installiert
- Migration: `users`, `user_groups`, `permissions`, `group_permissions`, `user_permission_overrides`, `user_scope_assignments`, `two_factor_secrets`
- Migration: `encryption_keys`, `key_wraps`, `recovery_keys`
- `PermissionResolver` + Middleware
- `CryptoService` mit Envelope Encryption (Argon2id KDF + AES-256-GCM Wrap)
- Setup-Wizard:
  - Schritt 1: Admin-User anlegen
  - Schritt 2: Schule (Name) eintragen
  - Schritt 3: Klarnamen-Passwort vergeben → DEK generieren
  - Schritt 4: Recovery-Key **einmalig anzeigen**, Bestätigung erzwingen
- Login + 2FA-Aktivierung (TOTP, freiwillig)
- Audit-Log Basis-Tabelle + Logger

### Definition of Done
- `docker compose up` startet alles
- Setup-Wizard durchklickbar, danach Admin-Login, Filament-Dashboard sichtbar
- Klarnamen-Passwortwechsel funktioniert (testbar mit Dummy-Crypt-Wert)
- 2FA-Aktivierung funktioniert
- Tests: Crypto-Service, Permission-Resolver

---

## Phase 1 – Stammdaten & Import (Wochen 3–4)

**Ziel:** Schuljahre, Lerngruppen, Schüler verwalten + SchiLD-CSV-Import mit Diff/Archiv.

### Lieferungen
- Migrationen: `school_years`, `learning_groups`, `students`, `student_enrollments`, `student_group_memberships`
- Filament-Resources: SchoolYear, LearningGroup, Student
- Klarnamen-Verschlüsselung am Student (Eloquent-Cast)
- Importer-Interface + `SchildCsvImporter`
- `ManualImporter` (Direkteingabe in der UI)
- Importassistent als Filament-Page (mehrstufig):
  1. Quelle + Schuljahr + Datei
  2. Mapping-Anzeige
  3. Validierung mit fehler-markierten Zeilen
  4. Diff-Anzeige inkl. Archivkandidaten, einzeln bestätig-/ausschließbar
  5. Commit-Bestätigung (mit 2FA, Permission `import.commit_archive`)
  6. Bericht
- Migrationen: `import_jobs`, `import_diff_entries`, `student_imports`
- Default-User-Klassen Seeder (Admin/Schulleitung/Sekretariat/Lehrkraft)
- Permission-Katalog Seeder
- Scope-Filter angewandt auf Student-Listen

### Definition of Done
- Admin kann Schuljahr und Klassen anlegen
- SchiLD-CSV importierbar, Klarnamen verschlüsselt gespeichert
- Re-Import erkennt bestehende Schüler, archiviert fehlende
- Lehrkraft mit Scope sieht nur ihre Lerngruppen
- Audit-Log zeigt alle Vorgänge
- Tests: Import-Diff-Algorithmus, Scope-Filter

---

## Phase 2 – Test-Engine (Wochen 5–7)

**Ziel:** Fragebögen, Normtabellen, Hinweistexte, Testdurchläufe, Schüler-Test-UI inklusive Übungsphase und LQ-Berechnung.

### Lieferungen
- Migrationen: `questionnaires`, `questionnaire_questions`, `questionnaire_practice_questions`
- Migrationen: `norm_tables`, `norm_table_rows`
- Migrationen: `feedback_sets`, `feedback_set_ranges`, `notice_texts`, `assessment_types`
- Migrationen: `test_runs`, `test_run_groups`, `test_run_security`
- Migrationen: `test_attempts`, `attempt_answers`, `attempt_lq_history`, `student_login_codes`
- Filament-Resources für alle obigen
- Fragebogen-Import via CSV/XLSX (mit Übungsfragen)
- Normtabellen-Import via CSV/XLSX (3D)
- `LqResolver` + `LqRecalculationService`
- Schüler-Test-UI (Inertia/Vue):
  - Login mit 10-stelligem Code
  - Hinweisseite
  - Übungsphase mit Mini-Timer
  - Hauptphase mit Countdown, Auto-Save, Antwortänderung möglich
  - Submit / Timeout
  - Ergebnisseite (LQ nur falls aus Norm ableitbar)
- Lehrkraft-Funktionen: Codes neu generieren, Versuche zurücksetzen
- Default-Erhebungstypen Seeder

### Definition of Done
- Kompletter Test-Lebenszyklus durchlaufbar
- Mehrere Erhebungen pro Schuljahr/Lerngruppe möglich
- LQ wird korrekt aus Norm berechnet (3D)
- LQ-Neuberechnung nach Norm-Änderung erzeugt History-Eintrag
- Scope-Default Lehrkraft funktioniert (eigene Lerngruppen)
- Tests: LQ-Resolver, Test-Engine-Flow (E2E mit Playwright/Dusk)

---

## Phase 3 – Auswertung & PDF (Wochen 8–10)

**Ziel:** Schüler-Längsschnittansicht mit Diagramm, aggregierte Auswertungen, Förderbedarf, alle Drucksachen serverseitig als PDF mit bearbeitbaren Vorlagen.

### Lieferungen
- Migrationen: `support_thresholds`, `print_templates`, `print_template_versions`, `print_jobs`, `generated_documents`, `export_logs`
- Schüler-Detailseite mit Verlaufsdiagramm (Chart.js)
- Aggregierte Auswertungen:
  - Kohorten-Übersicht (Filament-Page mit Filter + Charts)
  - Trend-Auswertung (besser/schlechter zwischen zwei Erhebungen)
  - Förderbedarfs-Liste auf Basis der konfigurierbaren Schwellen
- Default-Schwellen Seeder (LQ<85, LQ<70)
- Druckvorlagen-Verwaltung in Filament:
  - WYSIWYG mit TipTap
  - Variablen-Chips
  - Diagramm-Block-Komponente
  - Versionierung mit Diff-Anzeige
- System-Default-Templates (HTML+CSS) als Seeder:
  - Rückmeldebogen
  - QR-Code-Liste
  - Klassenergebnis
  - Verlaufsdiagramm
  - Förderbedarfs-Liste
  - Zugangsdaten-Druck
  - Serienbrief
- `PrintJobRunner` + `GotenbergClient`
- Asynchrone PDF-Erzeugung via Queue
- PDF-Download mit Audit-Logging

### Definition of Done
- Jede Auswertungsseite hat einen "Als PDF" Knopf
- Alle Drucksachen sind serverseitig erzeugte PDFs
- Templates können editiert und Versionen verglichen werden
- Frühere Versionen bleiben für Reproduzierbarkeit erhalten
- Klarnamen in PDFs nur mit aktiver Klarnamen-Session + Permission
- Tests: Druck-Pipeline, Threshold-Evaluator

---

## Phase 4 – Mail & Backup (Wochen 11–12)

**Ziel:** SMTP-Versand, Mailprotokoll, Backup-System mit Verschlüsselung und SFTP, Restore-CLI.

### Lieferungen
- Migrationen: `mail_settings`, `mail_messages`, `mail_attachments`
- Filament-Page: SMTP-Einstellungen, Test-Mail
- Mail-Versand-Service mit Anhang-Support (Verweis auf `generated_documents`)
- Mailprotokoll-Anzeige mit Filter
- Migrationen: `backup_targets`, `backup_runs`
- Backup-Service:
  - DB-Dump (mariadb-dump)
  - Storage-Tar (Uploads, Templates, Konfig)
  - AES-256-Verschlüsselung vor Upload
  - SFTP-Upload (`league/flysystem-sftp-v3`)
  - Lokales Ziel als Default
  - Cron via Laravel Scheduler
- Retention-Policy-Anwendung
- Restore-CLI: `php artisan backup:restore <file>`
- Backup-Status auf Dashboard

### Definition of Done
- SMTP-Test-Mail kommt an
- Versand mit PDF-Anhang funktioniert
- Tägliches Backup läuft via Cron, wird verschlüsselt auf SFTP-Ziel hochgeladen
- Manueller Backup-Knopf funktioniert
- Restore über CLI getestet (auf leerer DB)
- Tests: Mail-Service, Backup-Encryption, Restore-Roundtrip

---

## Phase 5 – SVWS & Reife (Wochen 13–15)

**Ziel:** SVWS-Adapter, UX-Feinschliff, Doku, OpenSource-Release.

### Lieferungen
- `SvwsApiImporter` (OpenAPI-Client für SVWS-NRW generieren mit `openapi-generator`)
- Konfigurations-UI für SVWS-Verbindung (URL, Token, Mapping)
- Importassistent erweitert um SVWS-Quelle
- DSGVO-Funktionen:
  - Auskunfts-Report pro Schüler (PDF)
  - Lösch-Workflow mit 4-Augen-Bestätigung
- UI-Polish: Tastatur-Shortcuts, Loading-States, Empty-States
- Onboarding-Hilfe / Inline-Tooltips
- Vollständige Dokumentation (Admin-Handbuch, Lehrkraft-Handbuch, Installations-Guide)
- README mit klarer Lizenz-/Materialhinweis (Verfahren ja, Originalmaterial nein)
- GitHub-Release-Setup (Workflows, Templates, Issue-Forms)
- Pre-built Container-Images via ghcr.io
- Beispiel-Daten und Beispiel-Templates zum Ausprobieren

### Definition of Done
- SVWS-Anbindung mit Test-Server erfolgreich
- DSGVO-Workflows getestet
- Doku vollständig
- Release v1.0.0 getaggt
- Andere Schule kann mit der Doku in <30 Minuten installieren

---

## Querschnitts-Aktivitäten (über alle Phasen)

| Bereich | Was |
|---|---|
| **Tests** | Pro Phase: Domain-Tests, Feature-Tests, mind. 1 E2E-Test |
| **CI** | GitHub Actions ab Phase 0 (Lint + Tests) |
| **Code-Qualität** | PHPStan Level 6+, Pint Code-Style, regelmäßige Refactor-Slots |
| **Security-Reviews** | Phase-Ende: Crypto/Permissions/Scope-Verhalten prüfen |
| **Doku** | Architektur-Entscheidungen als ADRs in `docs/adr/` festhalten |

---

## Risiken & Gegenmaßnahmen

| Risiko | Wahrscheinlichkeit | Wirkung | Maßnahme |
|---|---|---|---|
| Verlust der DEK durch User-Fehler | mittel | hoch | Recovery-Key-Workflow & Doku, Setup-Wizard erzwingt Bestätigung |
| Falsche LQ durch Normtabellen-Update | gering | hoch | History-Tabelle, manuelle Re-Berechnung mit Audit |
| Skalierung bei großer Schule (>2000 SuS) | gering | mittel | Längsschnitt-Queries indexiert, Async-Jobs für Bulk-PDF |
| Browser-Inkompatibilität Schüler-UI | gering | mittel | Test auf Chrome/Firefox/Safari/Edge, mobile Tablets |
| SVWS-API-Änderungen | mittel | mittel | Adapter sauber gekapselt, OpenAPI-Spec regenerierbar |
| Klarnamen-Leak via Browser-Cache | gering | hoch | `Cache-Control: no-store` für Seiten mit Klarnamen, Audit-Log |

---

## Nächste Schritte (sofort umsetzbar)

1. Repo initialisieren (`git init`, `LICENSE`, `.gitignore`, `README.md` mit Pflichtenheft-Link)
2. `infra/app/Dockerfile` + `docker-compose.yml` schreiben
3. Laravel-Projekt-Skelett anlegen
4. Phase 0 starten

Sobald die Pflichtenheft-Dokumente final freigegeben sind, kann Phase 0 beginnen.
