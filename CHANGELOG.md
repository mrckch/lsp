# Changelog

Alle nennenswerten Änderungen in diesem Projekt sind hier dokumentiert. Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

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
