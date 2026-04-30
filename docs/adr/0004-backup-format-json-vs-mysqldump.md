# 0004 — JSON-Dump vs. mysqldump für Backups

**Status:** accepted (mit Folge-Item)
**Datum:** 2026-09-15 (v1.31.0 / v1.35.0)

## Kontext

Backups müssen DB-Inhalte + Storage-Files sichern und verschlüsseln. Pflichtenheft
empfiehlt mysqldump für Production. Die naive Variante (in v0.5 gestartet) nutzte
JSON-Row-Dumps via `DB::table()->get()->toArray()`.

## Entscheidung (Stand v1.35.0)

Wir bleiben bei **JSON-Dumps** für DB-Inhalte. Begründung:

1. **Schul-Größenordnung**: typische Datenmengen sind klein (max ~5000 SuS, einige
   100k Audit-Einträge). JSON ist robust und debugbar.
2. **Cross-DB-Kompatibilität**: JSON-Rows funktionieren mit SQLite (Tests/Dev) und
   MariaDB (Production) gleichermaßen — kein extra Tool im Container.
3. **Restore-Logik kann Schema-Drift handhaben**: `array_intersect_key` mit aktuellen
   Spalten, Schema-Drift-Erkennung (v1.38.0) bei Spalten-Mismatch.

Für **Storage-Files** wird base64-Content im Manifest mitgeführt
(`config('lsp.backup.include_paths')` whitelistet `lsp/imports`, `lsp/print-jobs`,
`lsp/exports`). Dateien > `max_file_size_bytes` (Default 50 MB) werden mit
`skipped_reason='datei_zu_gross'` markiert (Pfad bleibt im Manifest, Content nicht).

## Bewusst weggelassen

- **mysqldump-Path** für Production: würde Triggers, Stored Procedures, Charsets
  besser erfassen. **Aber**: erfordert mysqldump-Binary im `app`-Container, plus
  separate Restore-Logik (SQL-Stream statt JSON-Insert). Aktuell hat das LSP keine
  Triggers/Procedures, daher kein Mehrwert. Folge-Backlog-Punkt, falls ein User mit
  großen Production-Datenmengen das nachfragt.

## Konsequenzen

### Vorteile
- Backup + Restore funktionieren transparent in SQLite (Tests) und MariaDB.
- Kein extra Container-Tooling.
- Ein Backup ist eine einzelne `.bin`-Datei, base64+JSON dekodierbar mit `--dry-run`.

### Nachteile
- JSON-Serialisierung von ALLEN Tabellen-Rows in Memory: bei sehr großen Tabellen
  (>1 Mio. Rows) kann Memory eng werden. Aktuell unkritisch.
- Auto-Increment-Sequences werden bei Restore via SQLite-spezifischem
  `DELETE FROM sqlite_sequence` resettet; bei MariaDB nicht nötig (TRUNCATE setzt zurück).

## Implementation

- `app/Domain/Backup/BackupRunner.php` (`dumpTables()`, `dumpFiles()`, `encrypt()`)
- `app/Domain/Backup/BackupRestorer.php` (`restoreFiles()`, Schema-Drift-Erkennung)
- `app/Console/Commands/BackupRestoreCommand.php` (CLI mit `--dry-run`, `--force`,
  `--snapshot-before`, `--allow-version-mismatch`)
