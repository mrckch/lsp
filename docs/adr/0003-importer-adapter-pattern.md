# 0003 — Adapter-Pattern für Schüler-Import

**Status:** accepted
**Datum:** 2026-04-29 (Phase 0, Refactor in 2026-08-15 / v1.34.0)

## Kontext

Schulen in NRW nutzen unterschiedliche Quellen für Schülerdaten:
- **SchiLD-NRW** liefert CSV-Exporte
- **SVWS-NRW** ist eine REST-API
- Manuelle Eingabe ist immer noch möglich

Das LSP soll alle Quellen unterstützen, ohne dass die Diff/Commit-Logik dupliziert wird.

## Entscheidung

**Adapter-Pattern** mit gemeinsamer Basisklasse:

- Interface `StudentImporter` definiert `key() / validate() / diff() / commit()`.
- **`AbstractStudentImporter`** implementiert die Diff/Commit-Logik einmal:
  - `validate()` ruft abstrakte `fetchRows()` auf, dann Field-Validation.
  - `diff()` erzeugt `ImportJob` + `ImportDiffEntry`-Records, vergleicht mit DB-Bestand,
    markiert Archivkandidaten.
  - `commit()` lädt `ImportDiffEntry`-Records in einer DB-Transaktion ab.
- Konkrete Importer (`SchildCsvImporter`, `SvwsApiImporter`) implementieren NUR die
  Quellen-spezifische `fetchRows()`-Methode plus `key()` und `externalIdSource()`.

**Auswahl** im Wizard via `ImporterFactory::make($sourceKey)`.

**Configurable** über die Tabelle `import_sources` (verschlüsselter `config_encrypted`-JSON
mit Credentials, Endpoint, etc.) — Filament-Resource zur Verwaltung.

## Konsequenzen

### Vorteile
- Neue Quelle = neue Adapter-Klasse mit ~50–100 Zeilen Code.
- Diff/Commit-Logik bleibt konsistent: gleiche Match-Anker (`external_student_id +
  external_id_source`), gleiches Archiv-Verhalten, gleicher Audit-Eintrag.
- Stufenfilter (`gradeFilter`) wird im Base implementiert und wirkt VOR Diff —
  Schüler außerhalb der Filterstufe werden weder angelegt noch archiviert.

### Nachteile
- `fetchRows()`-Vertrag muss strikt eingehalten werden (row-Format mit allen Feldern).
- Bei zukünftigen Quellen mit grundlegend anderem Format (z. B. ungetrennte Datensätze
  wie XML) muss vor Adapter-Implementation ggf. ein Pre-Parser her.

### Alternativen (verworfen)
- **ETL-Pipeline mit Stages** (Source → Transform → Sink): Overkill für Schul-Größenordnung.
- **Reiner Composition-Ansatz** (jeder Importer eigenständig): führte zu 280 Zeilen
  Code-Duplikation, Refactor in v1.34.0 abgeräumt.

## Implementation

- `app/Domain/Import/Contracts/StudentImporter.php`
- `app/Domain/Import/Adapters/AbstractStudentImporter.php`
- Adapter: `SchildCsvImporter`, `SvwsApiImporter`
- `app/Domain/Import/ImporterFactory.php`
- `app/Domain/Import/Models/ImportSource.php`
