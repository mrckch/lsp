# Plan: „Auswertung → Datenanalyse“

> **Für die umsetzende Session:** Dieser Plan ist in sich geschlossen. Lies zuerst
> §0 (Kontext & Konventionen), setze dann phasenweise um (§6). Jede Phase endet mit
> grünen Tests, Commit und Deploy (§8). Stand der Planung: 2026-09-22.

## Ziel

Neuer Menüpunkt **Auswertung → Datenanalyse** (neben „Verlauf (Schüler)“ und
„Förderbedarf“). Nutzer filtern Erhebungsdaten, vergleichen Gruppen grafisch und
exportieren das Ergebnis als **DIN-A4-PDF mit Klarnamen** oder CSV.

**Entscheidungen des Auftraggebers**
- Nutzer: **alle** – Lehrkräfte (nur eigene Lerngruppen), Schulleitung, Admin.
- Wichtigste Vergleiche: **Klassen untereinander** und **Mädchen vs. Jungen**.
- PDF **mit echten Namen** (Klarnamen), wo Einzelwerte gezeigt werden.
- Alle Ansichten planen; Umsetzung in Phasen.

---

## 0. Kontext & Konventionen (bitte einhalten)

- **Stack:** Laravel 12, PHP 8.3+, Filament **v3.3**, Livewire 3. Admin-Panel unter
  `/admin`, ein Panel für alle Rollen (`app/Providers/Filament/AdminPanelProvider.php`).
- **Kein Tailwind-Theme** im Panel → eigene Views nutzen Filament-Komponenten
  (`<x-filament::section>`, `<x-filament::button>` …) und **Inline-Styles/`<style>`-Blöcke**
  mit Filament-CSS-Variablen (`rgb(var(--primary-600))`, `--gray-*`, `--warning-*`,
  `--danger-*`, `--success-*`), Dark-Mode über `.dark …`. Vorbild:
  `resources/views/filament/resources/test-run/lq-boxplot.blade.php`.
- **Charts = serverseitiges SVG in Blade** (kein JS-Chart-Framework). Vorteil: identisch
  auf Bildschirm und im PDF (Gotenberg rendert HTML). Hover via SVG `<title>`.
- **Filament-Closure-Parameter werden per Name injiziert** (`$state`, `$record`, `$get`,
  `$set`, `$livewire`, `$data` …) oder per Typ (z. B. `fn (Builder $q)`, `fn (Student $r)`).
  Ein freier Name wie `fn (int $s)` erzeugt einen 500er (war schon einmal so).
- **Berechtigungen** über `App\Domain\Permission\PermissionResolver` / `$user->hasPermission()`
  und **Scope** über `App\Domain\Permission\ScopeFilter` (`applyToAttempts`,
  `applyToStudents`, `applyToLearningGroups`, `applyToTestRuns`). `scopesFor($user) === null`
  ⇒ sieht alles (Admin/Schulleitung). Pages nutzen den Trait
  `App\Filament\Concerns\AuthorizedPage` (`requiredPermission()`).
- **Klarnamen** sind verschlüsselt (`students.first_name_encrypted` / `last_name_encrypted`,
  Cast `App\Domain\Crypto\Casts\EncryptedName` → liefert `***`, wenn gesperrt).
  Der Schlüssel ist **session-gebunden** (`CryptoService::isUnlocked()`), daher
  **PDFs mit Namen synchron im Request erzeugen** (nicht in einer Queue).
  Vorbild: `TestRunResource::downloadLoginCards()`.
- **PDF:** `App\Domain\PrintJob\GotenbergClient::htmlToPdf($html)`; Fehler freundlich
  melden (Trait `App\Filament\Concerns\HandlesPrintErrors`). Erzeugte Dateien optional
  als `App\Domain\PrintJob\Models\GeneratedDocument` ablegen (Liste „Erzeugte Dokumente“,
  Felder: file_name, file_path, mime_type, size_bytes, includes_clearnames, sha256,
  expires_at, created_by_user_id).
- **Audit:** `App\Domain\Audit\AuditLogger::logUser($user, 'analysis.export_pdf', null, null,
  [...filter], includesClearnames: true)`.
- **Farben:** Kategorisch (validiert, CVD-sicher): Mädchen `#2a78d6` (dark `#3987e5`),
  Jungen `#eb6834` (dark `#d95926`), divers/unbekannt Grau; **immer zusätzlich Form**
  (Mädchen Kreis, Jungen Quadrat, sonst Raute). Weitere Kategorien (Klassen) –
  Reihenfolge: `#2a78d6, #eb6834, #1baf7a, #eda100, #e87ba4, #008300` – ab 7 Gruppen
  keine Farbe je Klasse, sondern Beschriftung (Boxplots haben ohnehin Zeilenlabels).
  Förderbereiche: danger/warning/success-Töne als Flächen, **immer mit Legende + Zahl**.
- **Tests:** PHPUnit-Feature-Tests (`php artisan test`), Muster siehe
  `tests/Feature/Filament/MonitorTestRunTest.php` (Seeder `PermissionCatalogSeeder`,
  `DefaultUserGroupsSeeder`; `PermissionResolver(useCache: false)`;
  `CryptoService::initialize($admin, '…')`; `Livewire::test(...)`). Code-Style: `vendor/bin/pint`.
- **Sprache der UI:** Deutsch. Kommentare im Code: Deutsch, knapp.

### Vorhandene Bausteine (wiederverwenden!)
| Baustein | Pfad | Nutzen |
|---|---|---|
| Boxplot-Statistik (Quantile R-Typ 7, Tukey-Whisker, Förderbereiche aus Schwellen) | `app/Filament/Resources/TestRunResource/Widgets/TestRunLqBoxplot.php` (`stats()`, `quantile()`, `bands()`) | → in Domain-Service auslagern (Phase 1) |
| Boxplot-SVG inkl. Geschlechter-/Bereichs-Umschalter | `resources/views/filament/resources/test-run/lq-boxplot.blade.php` | → in Blade-Komponente überführen |
| Fortschritt/Scope je Run | `app/Domain/TestRun/TestRunProgress.php` | Muster „letzter Versuch je Schüler“ |
| Kohorte / Trend Δ-LQ | `app/Domain/Analytics/AnalyticsService.php` (`cohort()`, `trend()`) | Phase 2 „Entwicklung“ |
| Förderschwellen | `app/Domain/SupportThreshold/Models/SupportThreshold.php` (metric `lq_absolute`, operator `lt`/`le`, severity `foerderbedarf`/`auffaellig`/`hinweis`) | Bereiche |
| HTML→PDF | `app/Domain/PrintJob/GotenbergClient.php`, Muster `LoginCardSheetGenerator` | A4-Export |
| Bestehende Auswertungsseiten | `app/Filament/Pages/StudentHistoryChart.php`, `SupportListPage.php` | Navigationsgruppe „Auswertung“, Stil |

### Datenmodell (relevant)
- `test_attempts`: student_id, test_run_id, status (`abgegeben`/`zeit_abgelaufen` = gewertet),
  lq_current, score_raw, submitted_at, main_started_at, time_limit_seconds, parallel_form, questionnaire_id
- `attempt_answers`: test_attempt_id, question_id, given_answer, is_correct, answered_at
- `test_runs`: school_year_id, assessment_type_id (Erhebungstyp, z. B. Herbst/Frühjahr), questionnaire_id, status, name, scheduled_for
- `test_run_groups` (Pivot Run↔Lerngruppe), `learning_groups` (school_year_id, name, group_type klasse/kurs, grade_level)
- `student_group_memberships` (student_id, learning_group_id, school_year_id)
- `student_enrollments` (student_id, school_year_id, grade_level, is_repeater)
- `students`: gender (`m`/`w`/`d`/`unbekannt`), status, verschlüsselte Namen
- `questionnaire_questions`: sort_order, question_text, correct_answer

---

## 1. Berechtigung & Navigation

- Neue Page `App\Filament\Pages\DataAnalysisPage`, Navigationsgruppe **„Auswertung“**,
  Label **„Datenanalyse“**, Icon `heroicon-o-chart-pie`, Sortierung nach „Förderbedarf“.
- `requiredPermission()` → **`analytics.cohort_overview`** (existiert; Lehrkraft,
  Schulleitung, Admin haben sie laut `PermissionCatalog::defaultsForGroups()` – prüfen;
  falls Schulleitung fehlt, im Catalog ergänzen + Seeder läuft idempotent).
- Jahrgangs-/schulweite Vergleiche über fremde Gruppen: nur mit
  **`analytics.school_overview`** (existiert, nicht scopeable). Ohne diese Permission
  wird alles strikt auf den Scope gefiltert (Lehrkraft sieht nur eigene Klassen).
- PDF mit Namen: zusätzlich **`print.generate_with_clearname`** + entsperrte
  Klarnamen-Session; sonst Hinweis „Bitte Klarnamen entsperren“ (wie Login-Karten).
- Jede Aktion mit Namen → Audit-Eintrag (`includes_clearnames: true`).

## 2. Domain-Schicht (neu, `app/Domain/Analytics/`)

### 2.1 `AnalysisFilter` (readonly DTO)
Felder (alle optional): `schoolYearId`, `assessmentTypeIds[]`, `testRunIds[]`,
`gradeLevels[]`, `learningGroupIds[]`, `genders[]` (w/m/other), `repeater` (null/true/false),
`parallelForms[]`, `dateFrom`, `dateTo`, `groupBy` (enum: `none`, `learning_group`,
`gender`, `grade_level`, `test_run`, `assessment_type`, `parallel_form`),
`secondaryGroupBy` (für „Klasse × Geschlecht“), `basis` (`latest_per_student` Standard |
`all_attempts`).
- `fromArray()/toArray()` für Livewire-Form + URL-Query (teilbare Links) + gespeicherte Presets.
- `describe(): string` → menschenlesbare Filterbeschreibung für PDF-Kopf.

### 2.2 `AnalysisDataset` (Query-Service)
`rows(AnalysisFilter $f, User $u): Collection` – **eine** scope-gefilterte Abfrage,
Ergebnis je gewertetem Versuch:
`attempt_id, student_id, student (Model, für Namen), gender, learning_group_id,
learning_group_name, grade_level, is_repeater, test_run_id, test_run_name,
assessment_type, school_year_id, parallel_form, lq, raw, answered_count, submitted_at`.
- Nur Status `abgegeben`/`zeit_abgelaufen`, `lq_current` nicht null (raw auch ohne LQ nutzbar → Flag).
- Lerngruppe: die Mitgliedschaft im Schuljahr des Runs, die zu den Run-Gruppen gehört
  (Schüler in mehreren Kursen → Klasse (`group_type = klasse`) bevorzugen).
- `basis = latest_per_student`: je Schüler **und** Gruppierungsschlüssel der jüngste Versuch
  (bei `groupBy=test_run/assessment_type` also je Erhebung einer).
- Scope: `ScopeFilter::applyToAttempts()`; ohne `analytics.school_overview` zusätzlich
  Lerngruppen-Filter auf eigene Scopes begrenzen.
- `answered_count` per `withCount('answers')`.

### 2.3 `DistributionStats` (aus `TestRunLqBoxplot` auslagern)
- `quantile()`, `summary(array $values)` → n, mean, sd, min, q1, median, q3, max,
  whisker lo/hi, outliers, below-Threshold-Zählungen.
- `bands(array $values)` → Förderbereiche aus `SupportThreshold` (Logik 1:1 aus dem Widget).
- `TestRunLqBoxplot` danach auf `DistributionStats` umstellen (Verhalten unverändert,
  bestehende Tests `MonitorTestRunTest` müssen grün bleiben).

### 2.4 `AnalysisReport` (Aggregation je Ansicht)
Methoden liefern reine Arrays (von Blade & PDF gleich genutzt):
- `groupedDistribution($rows, $groupBy, $secondary = null)` → je Gruppe summary + Punkte.
- `bandShares($rows, $groupBy)` → je Gruppe Anzahl/Anteil je Förderbereich.
- `development($rows)` → je Erhebung (chronologisch) Median/Q1/Q3 + Δ je Schüler
  (nutzt/erweitert `AnalyticsService::trend()`).
- `histogram($rows, binWidth = 5)` + Normkurve N(100, 15) skaliert auf n.
- `speedAccuracy($rows)` → je Schüler (answered_count, Fehlerquote = 1 − raw/answered).
- `itemAnalysis(AnalysisFilter)` → je Satz: n bearbeitet, Lösungsquote, Anteil „nicht erreicht“
  (nur wenn Filter auf **einen** Fragebogen eindeutig ist, sonst Hinweis).
- **Mindestgruppengröße:** Gruppen mit n < 3 in Aufschlüsselungen als „zu klein“
  markieren (keine Box, nur Punkte) – Wert als Konstante, später konfigurierbar.

## 3. Darstellung – wiederverwendbare Blade-Komponenten

Unter `resources/views/components/analysis/` (anonyme Komponenten):
- `boxplot.blade.php` – **mehrzeilig**: eine Zeile je Gruppe (Label links, n rechts),
  gemeinsame x-Achse (LQ), Punkte (Jitter deterministisch), optional Geschlechts-
  Kodierung (Farbe+Form), optional Förderbereich-Flächen, Linien Schwelle & Normmittel 100.
  Props: `groups`, `domain`, `bands`, `showBands`, `byGender`, `print` (dickere Linien,
  keine Hover-Hitboxen). Höhe = Zeilen × ~46 px. Die Monitor-Ansicht nutzt sie mit einer Zeile.
- `stacked-bands.blade.php` – 100-%-Balken je Gruppe, Segmente Förderbedarf/auffällig/
  unauffällig, Anzahl im Segment (wenn breit genug) + Legende mit Zahlen.
- `development.blade.php` – x = Erhebungen, Median-Linie + Q1–Q3-Band je Gruppe
  (max. 6 Gruppen, sonst Hinweis) + Δ-Verteilung als kleiner Boxplot.
- `histogram.blade.php` – Balken (Bin 5 LQ) + Normkurve als Linie + Mittelwertlinie.
- `scatter.blade.php` – Tempo (x: bearbeitete Sätze) vs. Fehlerquote (y), Quadranten-
  Hilfslinien am Median, Punkte mit Name im `<title>`.
- `stats-table.blade.php` – Kennzahlentabelle (n, MW, SD, Median, Q1, Q3, Min, Max,
  % < Schwellen), Zeile „Gesamt“.
- Gemeinsame Regeln: Text in Grau-Tokens (nie in Serienfarbe), dünne Linien, Legende
  bei ≥ 2 Serien, `role="img"` + `aria-label` mit Kernaussage, Dark-Mode-Styles.

## 4. Die Seite `DataAnalysisPage`

Layout (von oben):
1. **Filterzeile** (Filament-Form, `->live()`, in einer `Section`, einklappbar):
   Schuljahr (Default: aktuelles) · Erhebungstyp · Testdurchläufe · Jahrgang ·
   Lerngruppen · Geschlecht · Wiederholer · Parallelform · **Gruppieren nach** ·
   **Zusätzlich aufteilen nach** (z. B. Geschlecht) · Basis.
   Optionen der Auswahllisten bereits scope-gefiltert. Filterzustand per
   `#[Url]`-Property in der URL (teilbar).
2. **Kopfkennzahlen** (Stat-Kacheln): n SuS · Median · Anteil Förderbedarf · Anteil auffällig.
3. **Tabs** (Livewire-Property `$view`):
   - **Vergleich** (Standard): mehrzeiliger Boxplot + Schalter „Förderbereiche“ /
     „Nach Geschlecht“ + Kennzahlentabelle darunter.
   - **Förderbereiche**: gestapelte Balken + Tabelle.
   - **Entwicklung**: Herbst → Frühjahr (erfordert ≥ 2 Erhebungen im Filter, sonst Hinweis).
   - **Verteilung vs. Norm**: Histogramm.
   - **Tempo & Genauigkeit**: Scatter.
   - **Satzanalyse**: Tabelle + Balken je Satz.
4. **Drill-down:** Klick auf eine Gruppenzeile (Boxplot/Balken) → Modal/Slide-over mit
   Schülerliste der Gruppe (Name, Klasse, LQ, Rohwert, Förderbereich), sortierbar.
5. **Header-Aktionen:** „PDF (A4)“ (Modal: Ansichten wählen [Checkboxen], Hoch/Quer,
   „Schülerliste je Gruppe anhängen“ [Standard an]) · „CSV exportieren“ ·
   „Auswertung speichern“ · „Gespeicherte Auswertungen“ (Dropdown).

Typische Voreinstellungen als Schnellwahl-Buttons über der Filterzeile:
**„Klassen vergleichen“** (groupBy = learning_group, Jahrgang wählen) und
**„Mädchen vs. Jungen“** (groupBy = gender) und **„Klassen × Geschlecht“**
(groupBy = learning_group, secondary = gender → pro Klasse zwei Zeilen/Farben).

## 5. PDF-/CSV-Export

- Service `App\Domain\Analytics\AnalysisPdfRenderer::html(AnalysisFilter, array $views, string $orientation, bool $withStudentLists): string`
  – eigene Print-Blade-View `resources/views/print/analysis.blade.php`:
  - `@page { size: A4 portrait|landscape; margin: 14mm }`, Systemschrift, keine
    Filament-Variablen (Farben fest aus §0).
  - **Kopf** je Seite: Schulname (`AppSetting::singleton()->school_name`), Titel
    „Datenanalyse Lese-Screening“, Filterbeschreibung (`AnalysisFilter::describe()`),
    Erstellt am/von. **Fuß:** „Vertraulich – enthält Klarnamen“ + Seitenzahl.
  - Je gewählte Ansicht ein Abschnitt (Komponenten mit `print=true`), Seitenumbruch
    zwischen Ansichten (`break-before: page`), Tabellen mit `thead` (wiederholt sich).
  - **Schülerlisten mit Klarnamen** je Gruppe: Name, Klasse, Geschlecht, LQ, Rohwert,
    Förderbereich; sortiert nach LQ aufsteigend (Förderbedarf zuerst).
- Erzeugung **synchron** in der Livewire-Action (Klarnamen-Schlüssel ist session-gebunden):
  Prüfung `CryptoService::isUnlocked()` + Permission → HTML → `GotenbergClient` →
  `response()->streamDownload(..., 'datenanalyse-YYYYMMDD-HHMM.pdf')`; zusätzlich als
  `GeneratedDocument` (includes_clearnames = true, expires_at = +30 Tage) speichern;
  Audit `analysis.export_pdf`.
- CSV (`;`-getrennt, UTF-8 mit BOM für Excel): Kennzahlentabelle **und** optional
  Einzelwerte (mit Namen nur bei entsperrten Klarnamen + Permission, Audit `analysis.export_csv`).

## 6. Umsetzung in Phasen

### Phase 1 – Kern (Klassen- & Geschlechtervergleich, PDF) — ✅ umgesetzt (Branch `feat/data-analysis`)
> Abweichungen: Tabs erst ab Phase 2 (Phase 1 zeigt nur „Vergleich“). Lehrkräfte **ohne**
> Lerngruppen-Zuweisung sehen hier nichts (strenger als `ScopeFilter`, der „keine Zuweisung“
> als „alles“ wertet). PDF ohne Schülerlisten geht auch bei gesperrten Klarnamen.
1. `DistributionStats` extrahieren, `TestRunLqBoxplot` darauf umstellen (Tests grün).
2. `AnalysisFilter`, `AnalysisDataset` (+ Tests: Scope, latest_per_student, Gruppenzuordnung).
3. Komponenten `boxplot` (mehrzeilig) + `stats-table`; Monitor-Widget nutzt `boxplot`.
4. `DataAnalysisPage` mit Filtern, Schnellwahl, Tab **Vergleich**, Drill-down.
5. PDF-Export (Vergleich + Tabelle + Schülerlisten) und CSV.
6. Berechtigungen/Scope-Tests (Lehrkraft sieht nur eigene Klassen; Schulleitung alles).

### Phase 2 – Förderbereiche & Entwicklung — ✅ umgesetzt (Branch `feat/data-analysis`)
> Umsetzung: Welle = Erhebungstyp im Schuljahr (ohne Typ: der einzelne Run). Δ-Schwelle aus der aktiven
> `lq_delta`-Förderschwelle (Standard Δ < −10). Bei mehr als 2 Linien keine Q1–Q3-Bänder (unleserlich),
> ab 7 Gruppen nur die Gesamtlinie. Presets speichern `settings` = {filters, view, show_bands, by_gender, dev_from, dev_to}.
7. `stacked-bands` + Tab **Förderbereiche** (inkl. PDF).
8. Tab **Entwicklung** (Herbst → Frühjahr) mit `development`-Komponente; Δ-Liste
   „stärkste Verschlechterungen“ (mit Namen) für Förderkonferenzen.
9. **Gespeicherte Auswertungen**: Tabelle `analysis_presets` (id, user_id, name,
   filter JSON, is_shared bool, timestamps); eigene + geteilte Presets laden.

### Phase 3 – Vertiefung
10. **Verteilung vs. Norm** (Histogramm + N(100,15)).
11. **Tempo & Genauigkeit** (Scatter; Quadranten „langsam & genau“, „schnell & fehlerhaft“ …).
12. **Satzanalyse** (Lösungsquote je Satz, „nicht erreicht“-Anteil).

## 7. Tests (je Phase ergänzen)
- `tests/Feature/Analytics/DistributionStatsTest.php` – Quantile, Whisker, Bänder (Werte
  aus bestehendem `MonitorTestRunTest` übernehmen).
- `tests/Feature/Analytics/AnalysisDatasetTest.php` – Scope (Lehrkraft vs. Admin),
  `latest_per_student`, zurückgesetzte Versuche ignoriert, Klasse vor Kurs, Filter je Feld.
- `tests/Feature/Filament/DataAnalysisPageTest.php` – Seite lädt je Rolle, Filter per URL,
  Tabs rendern, Drill-down zeigt nur Scope-Schüler, PDF-Action verweigert ohne
  Entsperrung/Permission, erzeugt mit (Gotenberg per `Http::fake()` bzw. Client mocken),
  Audit-Eintrag vorhanden, CSV-Inhalt.
- Sichtprüfung: lokal mit SQLite-Wegwerf-DB + Playwright (Viewport 1024×768 = iPad quer,
  1366×900 Laptop), PDF einmal real über Gotenberg auf der VM erzeugen und ansehen.

## 8. Betrieb / Deploy (aktueller Stand, bitte beachten)
- Arbeitsbranch bisher: `feat/testrun-actions-and-code-print` (origin/main ist älter;
  kein PR offen). Für dieses Feature neuen Branch `feat/data-analysis` von dort abzweigen.
- Produktion: DockerVM1, `/opt/lsp` (Bind-Mount), SSH `root@100.64.34.3` (Tailscale) bzw.
  `root@192.168.1.40` (LAN). Die VM läuft mit **hand-kopierten Dateien** – Deploy:
  1. md5 der zu ersetzenden Dateien auf der VM mit dem erwarteten Basis-Commit vergleichen,
  2. `tar -cf - <dateien> | ssh … 'cd /opt/lsp && tar -xf - --no-same-owner'`,
  3. `docker compose exec -T -u lsp app php artisan migrate --force` (bei Migrationen),
     `route:cache`, `view:clear`, `filament:optimize-clear`.
  Storage-schreibende Artisan-Befehle immer mit `-u lsp`.
- Scheduler läuft (seit Fix 6627cbf) – Backups nachts 02:30.

## 9. Offene Punkte / später
- Mindestgruppengröße konfigurierbar machen (AppSetting).
- Normtabellen-Hinweis: LQ ist ggf. bereits geschlechtsspezifisch normiert – im
  Geschlechtervergleich als Fußnote anzeigen (Info aus `norm_tables` ableitbar).
- Mehrjahres-Längsschnitt (Jahrgang über Schuljahre) – erst wenn Daten aus ≥ 2 Jahren vorliegen.
