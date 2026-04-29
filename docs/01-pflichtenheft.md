# LSP – Lese-Screening-Portal · Pflichtenheft

**Stand:** 2026-04-29
**Projektname (Arbeitstitel):** LSP – Lese-Screening-Portal
**Lizenz:** EUPL 1.2
**Sprache:** Deutsch (ausschließlich)

---

## 1. Zielsetzung

Das LSP ist eine **Open-Source-Webanwendung zur Durchführung, Auswertung und Längsschnitt-Beobachtung digitaler Lese-Screenings** für Schulen in Deutschland. Es bildet das Verfahren des Salzburger Lese-Screenings (SLS) digital ab und ermöglicht die zentrale Verwaltung aller Lesediagnostik-Erhebungen einer Schule über die gesamte Schullaufbahn der Schülerinnen und Schüler (Klasse 5 bis 10, bei Wiederholern bis zu 8 Jahre).

Es ist die zentrale Anlaufstelle für:

- Vorbereitung und Durchführung von Lese-Screenings
- Längsschnitt-Beobachtung jedes einzelnen Schülers
- Aggregierte Auswertung auf Schul-, Jahrgangs-, Klassen- und Kursebene
- Identifikation von Förderbedarf
- Erzeugung von Rückmeldungen, Listen und Berichten als PDF

Das Portal ist als **Single-Tenant-Anwendung pro Schule** konzipiert (eine Installation = eine Schule).

---

## 2. Fachliches Verfahren (SLS)

### 2.1 Testablauf
- Schülerinnen und Schüler lesen still einfache Aussagesätze (z. B. "Bananen sind blau") und kreuzen pro Zeile **richtig** oder **falsch** an.
- Standardumfang: ca. 100 Sätze pro Testheft (variabel konfigurierbar).
- **Vorgeschaltete Übungsphase**: 30 Sekunden (Sekundarstufe I), Standardwert konfigurierbar.
- **Eigentlicher Test**: 180 Sekunden (3 Minuten), Standardwert pro Testdurchlauf überschreibbar (z. B. für Nachteilsausgleich).

### 2.2 Auswertung
- **Rohwert** = Anzahl korrekt beantworteter Sätze in der Testzeit.
- Der Rohwert wird über eine **Normtabelle** in einen **Lesequotienten (LQ)** umgerechnet.
  - Skala: Mittelwert 100, Standardabweichung 15.
- Normtabellen sind **dreidimensional**: `Schulstufe × Geschlecht × Parallelform → (Rohwert → LQ)`.
- Parallelformen (z. B. A1, A2, B1, B2) ermöglichen Vor-/Nachtests im selben Schuljahr ohne Wiedererkennungseffekt.

### 2.3 Förderbedarf
Vom Admin konfigurierbare Schwellen, Default-Vorschläge auf Basis der LQ-Skala:

- **LQ < 85** (1 SD unter Mittelwert) → "auffällig"
- **LQ < 70** (2 SD unter Mittelwert) → "deutlicher Förderbedarf"
- Zusätzlich konfigurierbar: negativer Trend (Δ-LQ über letzte n Erhebungen), Stagnation, Abweichung vom Klassenmedian.

### 2.4 Rechtlicher Rahmen
Das Original-SLS ist ein **kommerzielles Verfahren des Hogrefe-Verlags**. Diese Software bildet das Verfahren technisch ab, liefert aber **weder Original-Sätze noch Original-Normtabellen noch Original-Testhefte mit**. Schulen mit eigener Lizenz importieren ihre Materialien selbst (Fragebögen + Normtabellen). In Doku/README ist dies prominent zu kommunizieren.

---

## 3. Benutzerrollen und Berechtigungen

### 3.1 Benutzerklassen (frei anlegbar)
**Defaults beim Setup:**
- Admin
- Schulleitung
- Sekretariat
- Lehrkraft

Weitere Klassen kann der Admin jederzeit anlegen (z. B. "Förderkoordination", "Stufenleitung 5/6", "Beratungslehrkraft").

### 3.2 Permission-Modell
- **Permissions** sind ein fester, im Code definierter Katalog (jede Aktion einzeln benannt, z. B. `students.view`, `students.archive`, `clearname.unlock`, `print_template.edit`).
- **Benutzerklassen** bekommen Permissions zugewiesen → vererben sich auf alle Mitglieder.
- **User-Overrides** pro Benutzer:
  - **Grant**: zusätzlich gewähren
  - **Revoke**: explizit entziehen
- **Effektive Permissions** = (Σ Klassen-Permissions) ∪ User-Grants ∖ User-Revokes
- **Scoping**:
  - Permissions können **schulweit** oder **scoped auf Lerngruppen** vergeben werden.
  - **Default-Verhalten Lehrkraft**: Sieht alles, **außer** ihr sind Lerngruppen explizit zugewiesen → dann nur diese.
  - Default-Verhalten Sekretariat/Schulleitung: schulweit.
- 2FA (TOTP) optional pro User aktivierbar.

### 3.3 Schülerzugang
- Schüler haben **keinen dauerhaften Account**.
- Pro Test bekommen sie einen 10-stelligen **Einmal-Zugangscode** (auch als QR-Code).
- Nach Testabgabe ist der Code "verbraucht".
- **Reset** des Codes durch Admin **und** durch berechtigte Lehrkraft (im Rahmen ihrer Scopes).
- **Ergebnisanzeige** für Schüler nur, wenn Rohwert über die Normtabelle in einen LQ umgerechnet werden kann; sonst keine Anzeige.

### 3.4 Eltern
**Kein Zugang.**

---

## 4. Datenschutz und Verschlüsselung

### 4.1 Klarnamen-Verschlüsselung
**Modell: Envelope Encryption** (Standardverfahren wie AWS KMS, Bitwarden).

- Eine schulweite **DEK (Data Encryption Key)** verschlüsselt die personenbezogenen Klarnamen-Felder (Vorname, Nachname).
- Die DEK selbst wird **n-fach gewrapped** (verschlüsselt) gespeichert:
  - 1× pro berechtigtem Benutzer (Klarnamen-Passwort des jeweiligen Users)
  - 1× mit dem **Recovery-Key** (einmalig bei Setup angezeigt, im Schultresor zu verwahren)
- **Mehrere Klarnamen-Passwörter parallel**: Lehrkräfte können ihre Gruppen mit Klarnamen sehen (im Rahmen ihres Scopes), Admin und Schulleitung haben eigene Passwörter.
- **Passwortwechsel** eines Users: Nur seine eigene gewrappte DEK-Kopie wird neu erstellt (Sekundenoperation), Schülerdaten bleiben unangetastet.
- **Jährliche Rotation**: Admin kann eine Rotation erzwingen → alle User müssen beim nächsten Login ein neues Klarnamen-Passwort vergeben.
- **Recovery**: Bei Verlust aller Passwörter kann mit dem Recovery-Key ein neues Passwort gesetzt werden.
- **Scoping & Krypto sind entkoppelt**: Auch wenn ein Lehrer die DEK technisch entwrappen kann, sieht er Klarnamen nur für SuS in seinen zugewiesenen Lerngruppen (Permission-Layer).

### 4.2 Verschlüsselte Felder
- Vorname (Klarname)
- Nachname (Klarname)

### 4.3 Klartext-Felder
- Schülercode, Login-Code (Einmalcode)
- Externe IDs (SchiLD/SVWS) – pseudonyme Kennung
- Gruppenzugehörigkeit, Klassenstufe, Geschlecht
- Rohwert, LQ, Teststatus
- Audit-Metadaten

### 4.4 Audit
Audit-Log mit folgenden sicherheitsrelevanten Events (durchsuchbar im Admin, nach X Tagen archivierbar):

- Klarnamen-Entsperrung (wer, wann, warum)
- PDF-/Excel-Export mit Klarnamen
- Versand von E-Mails mit Klarnamen
- Anlage/Änderung/Archivierung/Löschung von Schülerdaten
- Änderung von Berechtigungen oder Benutzerklassen
- Login-Events (erfolgreich/fehlgeschlagen, 2FA-Trigger)
- Backup-/Restore-Operationen

### 4.5 DSGVO-Lebenszyklus
- **Aktiv → Archiv**: Schüler ist nicht mehr in der Schule. Daten bleiben erhalten, fallen aber aus den Tagesansichten.
- **Archiv → Löschung**: Manuell durch Admin, mit Filter nach Alter (z. B. "alle archivierten SuS älter als 5 Jahre anzeigen"), selektive Löschung.
- **Keine automatische Löschung.**
- **Recht auf Auskunft / Berichtigung / Löschung**: Admin-Funktionen, die DSGVO-Anfragen abbilden.

---

## 5. Stammdaten und Längsschnitt

### 5.1 Persistente Schüleridentität
- Jeder Schüler hat **eine einzige interne Schüler-ID**, die über die gesamte Schullaufbahn stabil bleibt (auch über Sitzenbleiben, Kurswechsel, Schuljahre hinweg).
- **Match-Anker beim Re-Import**: SchiLD-ID bzw. später SVWS-ID. Diese ist während der Schullaufbahn konstant.
- Aus den Schuljahren ergibt sich die Zuordnung zu Klassen/Kursen → **Enrollments pro Schuljahr**.

### 5.2 Lerngruppen
- Typen: **Klasse** und **Kurs**.
- Pro Schuljahr eindeutig benannt.
- Lehrkräfte können einzelnen Lerngruppen zugewiesen werden (Scope für ihre Sicht).

### 5.3 Importquellen (austauschbar via Adapter)
- **SchiLD-CSV** (sofort produktiv): `;`-getrenntes Format mit `ID;Name;Vorname;Klasse/Kurs;Geschlecht`.
- **SVWS-NRW-API**: architektonisch von Beginn an mitgedacht (Importer-Interface), aktiv erst in späterer Phase.
- **Manuell** (UI-Eingabe und ggf. CSV-Upload mit freiem Mapping).

### 5.4 Importassistent
Schrittfolge:

1. **Quelle wählen** (SchiLD-CSV / SVWS / manuell)
2. **Datei oder Verbindung prüfen**, Schuljahr wählen, Gruppentyp wählen
3. **Spalten-Mapping** anzeigen, ggf. anpassen
4. **Validierung / Dry-Run**: pro Zeile Status (gültig / Warnung / Fehler), fehlerhafte Zeilen markieren, Admin kann ausschließen
5. **Diff-Analyse**:
   - Neu hinzukommende SuS (Anlage)
   - Veränderte SuS (Klassenwechsel, Namensänderung)
   - **Im Import fehlende, aber im System aktive SuS → Archivkandidaten** (mit Begründung "nicht im aktuellen Import enthalten")
   - Admin bestätigt Archivierung pro Person oder gesamt; einzelne Archivierungen können ausgenommen werden
6. **Commit** in einer Transaktion: Anlage, Update, Archivierung + Audit-Log + Importprotokoll
7. **Bericht** mit Statistik (importiert / aktualisiert / archiviert / fehlgeschlagen)

---

## 6. Erhebungen (Testdurchläufe)

### 6.1 Erhebungs-Typ (Label)
- Vom Admin frei konfigurierbar (Defaults: "Eingangstest", "Abschlusstest", "Förderdiagnostik", "Zwischenerhebung").
- Pro Lerngruppe und Schuljahr **mehrere Erhebungen** möglich (z. B. Vor- und Nachtest).
- Das Label wird im Diagramm und in den Auswertungen angezeigt.

### 6.2 Test-Konfiguration
Pro Testdurchlauf wird festgelegt:

- Lerngruppen, die teilnehmen
- Fragebogen (= Testheft, mit zugeordneter Parallelform)
- Normtabelle (passend zu Schulstufe + Parallelform)
- Rückmeldeset (LaTeX-/HTML-Templates für Punktebereiche)
- Hinweistext (für die Schüler-Sicht)
- Zeitlimit (Default 180 Sek)
- Ob Schüler ihr Ergebnis sofort sehen
- Ob Lehrkraft Reset durchführen darf

### 6.3 Sicherheit pro Durchlauf
- Eigener **Lehrkraftcode** für Drittpersonen ohne Account (optional)
- Eigener **Klarname-Freigabecode** pro Durchlauf für temporäre Klarname-Anzeige

### 6.4 Datenpersistenz pro Versuch (Test-Attempt)
- **Rohwert** (immutable, exakt das Ergebnis im Testmoment)
- **Damals berechneter LQ** (Snapshot, basierend auf damaliger Normtabelle)
- **Aktueller LQ** (re-berechenbar bei Norm-Änderung)
- **Verwendete Normtabellen-Version** (Referenz, damit nachvollziehbar)
- **Verwendete Parallelform**
- Gegebene Antworten pro Frage

→ So bleibt die Historie der LQs nachvollziehbar, auch wenn die Normtabelle nachträglich angepasst wird.

---

## 7. Auswertung und Längsschnitt

### 7.1 Schüler-Einzelansicht
- **Persönliches Verlaufsdiagramm**:
  - X-Achse: Zeit (Erhebungsdatum mit Label)
  - Y-Achse: Lesequotient
  - Linie: persönlicher Verlauf
  - Optional einblendbare Vergleichslinien: Klassendurchschnitt, Jahrgangsdurchschnitt, Norm-Schwelle, Förder-Schwelle
- **Tabelle aller Erhebungen** mit Datum, Label, Lerngruppe, Rohwert, LQ
- **Druckbar als PDF** (serverseitig generiert)

### 7.2 Aggregierte Auswertung
Filterbar nach Schuljahr, Jahrgang, Klasse/Kurs, Erhebungstyp:

- **Übersicht aller Erhebungen** mit Mittelwerten, Streuung, Verteilung
- **Trend-Auswertung**: Wer ist besser/schlechter geworden (Δ-LQ zwischen zwei Erhebungen)
- **Förderbedarfs-Liste**: SuS unter den konfigurierten Schwellen
- **Klassenvergleich**: LQ-Verteilung pro Klasse
- **Jahrgangsvergleich** über Schuljahre

### 7.3 Druckbare Berichte
Alle Auswertungen sind als PDF exportierbar.

---

## 8. Drucksachen und Templates

### 8.1 Engine
**HTML + CSS + Gotenberg** (Chromium-basierte serverseitige PDF-Generierung).

Alle Drucksachen werden serverseitig generiert (kein Browser-Print).

### 8.2 Bearbeitbare Vorlagen im Admin
- **WYSIWYG-Editor** (TipTap oder vergleichbar) mit:
  - Variablen-Chips: `{{vorname}}`, `{{nachname}}`, `{{klasse}}`, `{{lq}}`, `{{punkte}}`, `{{verlaufsdiagramm}}`, `{{erhebungsdatum}}` etc.
  - Diagramm-Blöcke (vorgefertigte Komponenten, im PDF gerendert)
  - CSS für Layout/Typografie
- **Versionierung**: Alte Vorlagenversionen bleiben erhalten, sodass historische Rückmeldungen reproduzierbar sind.

### 8.3 Vorlagentypen
- Rückmeldebogen (pro Schüler)
- QR-Code-Liste (pro Lerngruppe / Testdurchlauf)
- Klassenergebnis (Übersicht)
- Verlaufsdiagramm (pro Schüler)
- Förderbedarfs-Liste
- Zugangsdaten-Druck (Benutzerkonten)
- Serienbrief-Anschreiben (Eltern/Schulleitung)

### 8.4 Generierung
- Asynchron via Queue (Redis).
- Größere Aufträge (z. B. komplette Klasse) als Bulk-Job mit Fortschrittsanzeige.
- Fertige PDFs werden zum Download bereitgestellt; Aufbewahrung konfigurierbar (Default: 30 Tage).

---

## 9. E-Mail-System

### 9.1 SMTP (Versand)
- Generischer SMTP-Anschluss (eigener Server, Schulträger-SMTP, IONOS, Posteo etc.).
- TLS Pflicht.
- Zugangsdaten im Admin verschlüsselt gespeichert.

### 9.2 Use-Cases
- Versand von Zugangsdaten (Benutzerkonten)
- Versand von Rückmeldungen als PDF-Anhang
- Versand von Berichten / Auswertungen
- System-Benachrichtigungen (Backup-Status, Import-Reports)

### 9.3 Kein IMAP
Empfang ist explizit nicht im Scope.

### 9.4 Mailprotokoll
Alle versendeten Mails werden mit Empfänger, Betreff, Anhang-Liste und Status (zugestellt/Fehler) protokolliert.

---

## 10. Backup und Restore

### 10.1 Inhalt
- DB-Dump (vollständig)
- Hochgeladene Dateien (Importdaten, Konfiguration, gespeicherte PDFs)
- Druckvorlagen
- Konfigurationsdateien
- Audit-Logs

### 10.2 Verschlüsselung
- AES-256 vor Upload an externe Ziele (Backup-Passwort/Schlüssel im Admin konfigurierbar).
- Klartext-Backups nur lokal, geschützt durch Container-Filesystem-Rechte.

### 10.3 Ziele
- Lokal (Container-Volume)
- **SFTP** (extern)
- Optional erweiterbar: S3-kompatibel (MinIO, Backblaze B2 etc.)

### 10.4 Trigger
- Cron (täglich, Uhrzeit konfigurierbar)
- Manuell aus dem Admin-UI

### 10.5 Retention
Konfigurierbare Policy, Default:

- 7 tägliche
- 4 wöchentliche
- 12 monatliche

### 10.6 Restore
- **Über CLI im Container** (gefährliche Operation, soll bewusst sein).
- Im UI nur **"Backup herunterladen"** und Status anzeigen.
- Restore prüft Integrität (Checksumme) und Schema-Version vor Anwendung.

---

## 11. Architektur und Technik

### 11.1 Stack
- **Backend**: Laravel 12 (PHP 8.3+)
- **Admin-UI**: Filament 3 (Laravel-natives Admin-Panel)
- **Schüler-/Lehrer-UI**: Inertia.js + Vue 3 (oder Filament-Custom-Pages, je nach UX-Bedarf)
- **API**: REST, OpenAPI 3.1 (Auto-Generierung via Scramble)
- **Auth**: Laravel Sanctum (SPA & Token), 2FA via `pragmarx/google2fa-laravel`
- **Permissions**: `spatie/laravel-permission` mit Erweiterung für Scopes und User-Overrides
- **DB**: MariaDB 11.x
- **Queue/Cache**: Redis 7
- **PDF**: Gotenberg (Docker-Container)
- **Reverse Proxy / TLS**: Caddy 2 (automatisches Let's Encrypt)
- **Container-Orchestrierung**: Docker Compose

### 11.2 Komponenten (Container)
- `app` (Laravel + PHP-FPM)
- `web` (Caddy)
- `db` (MariaDB)
- `cache` (Redis)
- `queue` (Laravel Queue Worker)
- `scheduler` (Laravel Scheduler / Cron)
- `pdf` (Gotenberg)
- `backup` (separater Worker für Cron-Backups)

### 11.3 Deployment
- **Dev**: lokaler Laptop (Windows + Docker Desktop / WSL2)
- **Prod**: Hetzner CX22 (2 vCPU, 4 GB RAM, 40 GB SSD) als Default-Zielklasse
- **Setup-Wizard** beim ersten Start: DB-Migration, Admin-Anlage, Klarnamen-Setup (DEK + Recovery-Key), Mail-Einstellungen, Backup-Ziel

### 11.4 Open-Source-Bausteine (statt Eigenentwicklung)
| Funktion | Paket |
|----------|-------|
| Admin-Panel | Filament 3 |
| Permissions | spatie/laravel-permission |
| Activity-Log | spatie/laravel-activitylog |
| Excel/CSV | maatwebsite/excel |
| QR-Codes | bacon/bacon-qr-code |
| 2FA | pragmarx/google2fa-laravel |
| OpenAPI | dedoc/scramble |
| Backup | spatie/laravel-backup |
| TipTap (Editor) | ueberdosis/tiptap |
| Charts | Chart.js / ApexCharts (Frontend) |

---

## 12. Nicht-funktionale Anforderungen

| Bereich | Anforderung |
|---------|-------------|
| Performance | 1000 SuS, 100 parallele Tests bedienen ohne spürbare Verzögerung |
| Verfügbarkeit | Best-Effort (Schulbetrieb, kein 24/7-SLA nötig) |
| Datensicherheit | Verschlüsselung at-rest (Klarnamen) + in-transit (TLS) |
| Barrierefreiheit | Schüler-UI: ausreichender Kontrast, Tastaturbedienbarkeit, große Klickflächen |
| Browser | Aktuelle Versionen von Firefox, Chrome, Edge, Safari (auch mobil/Tablet) |
| Geräte | Schüler-Test funktioniert auf Tablets und Laptops (responsive) |
| Internationalisierung | Sprache: nur Deutsch |
| Lizenz | EUPL 1.2 |

---

## 13. Roadmap (Phasen)

### Phase 0 – Fundament
- Projektstruktur, Docker-Compose, CI/CD-Skelett, Auth-Basis (User/Permissions/2FA), Setup-Wizard, Klarnamen-Krypto (Envelope Encryption + Recovery-Key)

### Phase 1 – Stammdaten & Import
- Schuljahre, Lerngruppen, Schüler (persistente ID, Enrollments), Importassistent (SchiLD-CSV) mit Diff-Analyse und Archivkandidaten

### Phase 2 – Test-Engine
- Fragebögen mit Parallelformen, Normtabellen (3D), Hinweistexte, Testdurchläufe, Schüler-Test-UI mit Übungsphase + Hauptzeit, Antwort-Persistenz, LQ-Berechnung

### Phase 3 – Auswertung & PDF
- Schüler-Längsschnittansicht mit Diagramm, aggregierte Auswertungen, Förderbedarfs-Liste, HTML-Templates mit WYSIWYG-Editor, Gotenberg-PDF, Versionierung der Templates

### Phase 4 – Mail & Backup
- SMTP-Konfiguration, Mailversand mit Anhang, Mailprotokoll, Backup-System (lokal + SFTP, verschlüsselt, Retention), Restore-CLI

### Phase 5 – SVWS & Reife
- SVWS-NRW-Adapter, weitere Berichte, UX-Feinschliff, OpenSource-Release

---

## 14. Außerhalb des Scopes

- IMAP / Posteingang
- Eltern-Zugang
- Mehrmandantenfähigkeit (eine Instanz, mehrere Schulen)
- Mehrsprachigkeit (über Deutsch hinaus)
- Mobile Native Apps
- Schüler-Selbstbedienung über das Sofort-Ergebnis hinaus
- Auto-Löschung archivierter Daten ohne Admin-Bestätigung
