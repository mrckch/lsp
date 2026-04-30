# 0005 — DSGVO-Lifecycle: Soft-Archive vor Hard-Delete

**Status:** accepted
**Datum:** 2026-08-15 (v1.28.0 + v1.33.0)

## Kontext

Audit-Logs enthalten potenziell personenbezogene Spuren (Klarnamen-Unlock,
Klarnamen-Provisioning, etc.). DSGVO erlaubt Audit-Logs zu Sicherheitszwecken,
verlangt aber Datensparsamkeit und definierte Aufbewahrungsfristen.

## Entscheidung

**Zweistufiger Lifecycle:**

1. **Soft-Archive** nach 90 Tagen (`config lsp.audit.archive_after_days`):
   - Cron `audit:archive` setzt `archived_at = now()` für ältere Einträge.
   - Filament-AuditLogPage blendet archivierte per Default aus (Filter
     `archive_mode=active`); Admin kann auf `all` oder `archived` umstellen.
   - **Daten bleiben in der DB**, sind nur nicht mehr im täglichen Workflow sichtbar.

2. **Hard-Delete** nach 730 Tagen (= 2 Jahren) ab Archivierung
   (`config lsp.audit.purge_after_days`):
   - Cron `audit:purge` löscht endgültig.
   - Bezugsdatum ist `archived_at`, nicht `created_at` — d. h. ein Eintrag wird erst
     gepurged, wenn er lang genug archiviert war.
   - Auf 0 setzen → Hard-Delete deaktiviert.

Beide Cron-Läufe schreiben einen eigenen Audit-Eintrag (`audit.archive` /
`audit.purge` mit Counter im Context).

## Konsequenzen

### Vorteile
- Granular pro Phase einstellbar (über `.env`-Variablen).
- Forensik bleibt 2+ Jahre nachvollziehbar; ältere Daten verschwinden hart.
- Zwischenphase „archiviert, aber abrufbar" für Compliance-Audits.

### Nachteile
- Eine Schule mit weniger Audit-Last könnte längere Aufbewahrung wollen — ist über
  Config möglich.
- Hard-Delete ist destruktiv — keine Wiederherstellung, kein Snapshot vorab. Wer
  die Daten halten will, muss sie aus einem Backup zurückspielen.

## Folge-Items

- Bei Bedarf: Export-Command (z. B. `audit:export-archived` als CSV/JSON) für
  externe Langzeit-Archivierung vor dem Purge.

## Implementation

- `app/Domain/Audit/Models/AuditLog.php` (`scopeActive()`, `scopeArchived()`)
- `app/Console/Commands/ArchiveAuditLogsCommand.php`
- `app/Console/Commands/PurgeArchivedAuditLogsCommand.php`
- `app/Filament/Pages/AuditLogPage.php` (Filter)
- `routes/console.php` (Schedules: täglich 03:30 / wöchentlich Sonntag 03:45)
