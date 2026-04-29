# LSP – Datenmodell

**Stand:** 2026-04-29
**Bezug:** ergänzt und ersetzt das Schema des Vorprojekts (`sls-projekt/lsp-webapp/sql/schema.sql`).

---

## 1. Konventionen

- Engine: **InnoDB**, Charset: **utf8mb4**, Collation: **utf8mb4_unicode_ci**
- Primärschlüssel: `id BIGINT UNSIGNED AUTO_INCREMENT`
- Zeitstempel: `created_at`, `updated_at` (DATETIME, ON UPDATE)
- Soft-Deletes: `deleted_at` (DATETIME NULL) wo sinnvoll
- Verschlüsselte Felder: `VARBINARY(512)` mit Suffix `_encrypted`
- Hashes: `VARCHAR(255)` (bcrypt/argon2id)
- Aufzählungen: nicht als ENUM, sondern als `VARCHAR` + Lookup-Tabelle, wo später erweiterbar (sonst Migrations-Overhead)
- Audit: zentrale `audit_logs`-Tabelle
- Migrations: Laravel-Migrations, eine pro logischer Änderung

---

## 2. Übersicht (Bereiche)

```
[Identität & Auth]      users, user_groups, user_group_memberships,
                        permissions, group_permissions,
                        user_permission_overrides, user_scope_assignments,
                        two_factor_secrets

[Krypto]                encryption_keys, key_wraps, recovery_keys

[Schule & Stammdaten]   school_years, learning_groups, students,
                        student_enrollments

[Test-Konfiguration]    questionnaires, questionnaire_questions,
                        questionnaire_practice_questions,
                        norm_tables, norm_table_rows,
                        feedback_sets, feedback_set_ranges,
                        notice_texts, assessment_types,
                        support_thresholds

[Erhebungen]            test_runs, test_run_groups, test_run_security,
                        test_attempts, attempt_answers,
                        attempt_lq_history

[Drucksachen]           print_templates, print_template_versions,
                        print_jobs, generated_documents

[Mail]                  mail_settings, mail_messages, mail_attachments

[Backup]                backup_targets, backup_runs

[Import]                import_sources, import_jobs, import_diff_entries,
                        student_imports

[Audit & Logs]          audit_logs, export_logs, login_logs
```

---

## 3. Identität & Authentifizierung

### users
Persönliche Benutzerkonten (keine Schüler).
```
id, username (unique), email (nullable), display_name,
password_hash, is_active,
two_factor_enabled (bool), two_factor_secret_id (nullable FK),
last_login_at, password_changed_at,
created_at, updated_at, deleted_at
```

### user_groups
Frei anlegbare Benutzerklassen.
```
id, name (unique), description, is_system (bool, default false),
sort_order, created_at, updated_at
```
Defaults beim Setup: Admin, Schulleitung, Sekretariat, Lehrkraft (`is_system = 1`).

### user_group_memberships
Ein User kann in mehreren Gruppen sein.
```
id, user_id, user_group_id,
created_at, updated_at,
UNIQUE(user_id, user_group_id)
```

### permissions
Statischer Katalog (im Code definiert, beim Migrate eingespielt).
```
id, key (unique, z.B. "students.view"), description, area (z.B. "students"),
is_scopeable (bool), created_at
```
- `is_scopeable = 1`: Permission kann auf Lerngruppen gescoped werden
- `is_scopeable = 0`: nur global vergebbar (z.B. `system.backup`)

### group_permissions
Permissions, die einer Benutzerklasse generell gegeben werden.
```
id, user_group_id, permission_id,
created_at, UNIQUE(user_group_id, permission_id)
```

### user_permission_overrides
Per-User-Overlay über Gruppen-Permissions.
```
id, user_id, permission_id,
mode ENUM('grant','revoke'),
created_at, UNIQUE(user_id, permission_id)
```

### user_scope_assignments
Welche Lerngruppen ein User sehen darf (Scope).
- Wenn keine Einträge vorhanden: User ist **ungescoped** (sieht alles).
- Wenn Einträge vorhanden: User ist auf diese Gruppen **eingeschränkt**.
```
id, user_id, learning_group_id,
created_at, UNIQUE(user_id, learning_group_id)
```

> **Default-Verhalten Lehrkraft**: Beim Anlegen ohne Scope-Einträge → schulweit. Sobald Lerngruppen zugewiesen sind → nur diese.

### two_factor_secrets
```
id, user_id (unique), secret_encrypted, recovery_codes_encrypted,
confirmed_at, created_at
```

---

## 4. Krypto (Envelope Encryption)

### encryption_keys
Eine **aktive DEK** (Data Encryption Key) zu jedem Zeitpunkt; ältere Versionen für Re-Encryption-Vorgänge.
```
id, key_version (unique), is_active (bool, nur eine pro Schule),
created_at, retired_at
```
- Die DEK selbst wird **niemals im Klartext gespeichert**.
- Sie existiert nur in Memory einer Session, nachdem sie entwrapped wurde.

### key_wraps
DEK gewrapped pro berechtigtem Schlüsselträger (User-Passwort oder Recovery-Key).
```
id, encryption_key_id (FK -> encryption_keys),
wrap_type ENUM('user_password','recovery_key'),
user_id (nullable, nur bei wrap_type=user_password),
wrapped_dek BLOB,                  -- mit KEK aus Passwort verschlüsselte DEK
kdf_salt BLOB,                     -- Salt für PBKDF2/Argon2id
kdf_params JSON,                   -- iterations / memory / parallelism
verification_hash VARCHAR(255),    -- für schnelle Passwort-Verifikation
created_at, updated_at,
UNIQUE(encryption_key_id, user_id, wrap_type)
```

### recovery_keys
Metadaten zum Recovery-Key (der Schlüssel selbst wird nicht gespeichert!).
```
id, encryption_key_id (FK), label, fingerprint VARCHAR(64),
created_at, used_at, revoked_at
```
- Beim Setup wird der Recovery-Key generiert, einmalig im UI angezeigt und ein Wrap dafür angelegt.
- `fingerprint` = SHA-256 des Recovery-Keys (zur Identifikation, nicht zur Wiederherstellung).

---

## 5. Schule & Stammdaten

### school_years
```
id, label (z.B. "2026/27", unique), start_date, end_date,
is_active, is_archived,
created_at, updated_at
```

### learning_groups
Klassen und Kurse (ehemals `groups`).
```
id, school_year_id (FK, ON DELETE CASCADE),
name (z.B. "5a"), description,
group_type ENUM('klasse','kurs'),
grade_level VARCHAR(10),           -- z.B. "5", "9", "EF"
sort_order, is_active,
created_at, updated_at,
UNIQUE(school_year_id, name, group_type)
```

### students
**Persistente Schüleridentität** über die gesamte Schullaufbahn.
```
id,                                 -- interne stabile Identität
external_student_id VARCHAR(50),    -- SchiLD-/SVWS-ID, Match-Anker
external_id_source VARCHAR(20),     -- 'schild' | 'svws' | 'manual'
student_code VARCHAR(20) UNIQUE,    -- LSP-eigenes Kürzel (anzeigetauglich)
first_name_encrypted VARBINARY(512),
last_name_encrypted VARBINARY(512),
gender ENUM('m','w','d','unbekannt') DEFAULT 'unbekannt',
date_of_birth_encrypted VARBINARY(512) NULL,   -- optional, falls für Norm relevant
status ENUM('aktiv','archiviert') DEFAULT 'aktiv',
archived_at DATETIME NULL,
archived_reason VARCHAR(255) NULL,
deleted_at DATETIME NULL,
created_at, updated_at,
UNIQUE(external_student_id, external_id_source)
```
- **NICHT** an `school_year_id` gebunden.
- Schullaufbahn-Daten kommen aus `student_enrollments`.

### student_enrollments
Schüler in welchem Schuljahr in welchen Lerngruppen.
```
id, student_id (FK),
school_year_id (FK),
grade_level VARCHAR(10),           -- für schnelle Filter (z.B. Klasse 7)
is_repeater (bool, default false), -- falls Sitzenbleiber
enrolled_at, ended_at,
created_at, updated_at,
UNIQUE(student_id, school_year_id)
```

### student_group_memberships
N:M Schüler ↔ Lerngruppen (innerhalb des Enrollments).
```
id, student_id, learning_group_id,
school_year_id (FK, redundant für schnelle Joins),
created_at, updated_at,
UNIQUE(student_id, learning_group_id)
```

---

## 6. Test-Konfiguration

### questionnaires
Testheft / Fragebogen mit Parallelform.
```
id, name, description,
parallel_form VARCHAR(10),          -- "A1","A2","B1","B2", frei
grade_level_target VARCHAR(20),     -- "5-6", "7-9", informativ
default_time_limit_seconds INT UNSIGNED DEFAULT 180,
practice_time_seconds INT UNSIGNED DEFAULT 30,
status ENUM('entwurf','aktiv','archiviert'),
created_by_user_id (FK -> users),
created_at, updated_at
```

### questionnaire_questions
```
id, questionnaire_id (FK, ON DELETE CASCADE),
sort_order INT UNSIGNED,
question_text TEXT,
correct_answer ENUM('richtig','falsch'),
is_active,
created_at, updated_at,
UNIQUE(questionnaire_id, sort_order)
```

### questionnaire_practice_questions
Übungsfragen (vor dem eigentlichen Test).
```
id, questionnaire_id (FK, ON DELETE CASCADE),
sort_order, question_text TEXT,
correct_answer ENUM('richtig','falsch'),
created_at, updated_at,
UNIQUE(questionnaire_id, sort_order)
```

### norm_tables
**3-dimensional**: Schulstufe × Geschlecht × Parallelform → (Rohwert → LQ)
```
id, name, version_label,
grade_level VARCHAR(10),            -- "5","6","7","8","9","10"
parallel_form VARCHAR(10),          -- muss zur Fragebogen-Form passen
source_type ENUM('csv','xlsx','manuell'),
status ENUM('entwurf','aktiv','archiviert'),
is_active (bool),
created_by_user_id (FK), created_at, updated_at
```

### norm_table_rows
```
id, norm_table_id (FK, ON DELETE CASCADE),
raw_score INT,
quotient_female INT,
quotient_male INT,
quotient_diverse INT NULL,           -- optional, sonst Mittel oder NULL
UNIQUE(norm_table_id, raw_score)
```

### feedback_sets
Sammlung von Rückmeldetexten/-bereichen.
```
id, name, status ENUM('entwurf','aktiv','archiviert'),
is_default (bool),
created_by_user_id, created_at, updated_at
```

### feedback_set_ranges
```
id, feedback_set_id (FK, ON DELETE CASCADE),
sort_order, name,
match_type ENUM('punkte','lq'),       -- worauf bezieht sich min/max
min_value INT, max_value INT,
template_html LONGTEXT,               -- HTML-Snippet für die Rückmeldung
is_active,
created_at, updated_at
```

### notice_texts
Hinweistexte für die Schüler-Sicht vor dem Test.
```
id, name, content TEXT,
is_default, status,
created_by_user_id, created_at, updated_at
```

### assessment_types
Frei konfigurierbare Erhebungs-Typen (Eingangstest etc.).
```
id, key (unique, z.B. "eingangstest"), label,
sort_order, is_active,
created_at, updated_at
```

### support_thresholds
Vom Admin konfigurierbare Förderbedarf-Schwellen.
```
id, name, description,
metric ENUM('lq_absolute','lq_delta','lq_below_class_median'),
operator ENUM('lt','le','gt','ge','eq'),
value INT,
window_count INT NULL,                -- für lq_delta: über letzte n Erhebungen
severity ENUM('hinweis','auffaellig','foerderbedarf'),
is_active,
created_at, updated_at
```

---

## 7. Erhebungen

### test_runs
Konkreter Testdurchlauf.
```
id, school_year_id (FK),
assessment_type_id (FK -> assessment_types),
name, short_code VARCHAR(20) UNIQUE,
status ENUM('in_vorbereitung','aktiv','pausiert','abgeschlossen','archiviert'),
questionnaire_id (FK), norm_table_id (FK),
feedback_set_id (FK), notice_text_id (FK),
time_limit_seconds INT UNSIGNED DEFAULT 180,
practice_time_seconds INT UNSIGNED DEFAULT 30,
show_score_to_student (bool),
allow_teacher_reset (bool),
scheduled_for DATE NULL,             -- Plan-Termin (informativ)
created_by_user_id (FK), owner_user_id (FK),
created_at, updated_at
```

### test_run_groups
Welche Lerngruppen am Durchlauf teilnehmen.
```
id, test_run_id (FK), learning_group_id (FK),
created_at,
UNIQUE(test_run_id, learning_group_id)
```

### test_run_security
Pro Durchlauf: Lehrkraftcode, Klarname-Freigabecode.
```
id, test_run_id (FK, UNIQUE),
teacher_access_code CHAR(12) NULL,
teacher_access_code_is_active (bool),
clearname_release_code_hash VARCHAR(255),
clearname_code_generated_at DATETIME,
created_by_user_id (FK), created_at, updated_at
```

### test_attempts
Ein Versuch eines Schülers in einem Durchlauf.
```
id, student_id (FK), test_run_id (FK),
questionnaire_id (FK),                -- Snapshot, damit auch nach Run-Update stabil
parallel_form VARCHAR(10),            -- Snapshot
norm_table_id (FK NULL),              -- Snapshot der zum Berechnungszeitpunkt verwendeten Norm
status ENUM('gestartet','abgegeben','zeit_abgelaufen','abgebrochen','zurueckgesetzt'),
started_at, submitted_at,
time_limit_seconds INT UNSIGNED,      -- Snapshot
score_raw INT UNSIGNED DEFAULT 0,     -- Rohwert (immutable nach Abgabe)
lq_at_submission INT NULL,            -- LQ zum Zeitpunkt der Abgabe (Snapshot)
lq_current INT NULL,                  -- aktuell berechneter LQ (kann nach Norm-Update neu berechnet werden)
lq_calculated_at DATETIME NULL,
ended_by ENUM('schueler','system','admin','lehrkraft') NULL,
reset_by_user_id (FK NULL), reset_reason VARCHAR(255),
login_code_used CHAR(10),             -- welcher Einmalcode
created_at, updated_at
```

### attempt_answers
```
id, test_attempt_id (FK, ON DELETE CASCADE),
question_id (FK),
given_answer ENUM('richtig','falsch'),
is_correct (bool),
answered_at DATETIME,
UNIQUE(test_attempt_id, question_id)
```

### attempt_lq_history
Historie der LQ-Berechnungen, falls Normtabelle nachträglich geändert wurde.
```
id, test_attempt_id (FK, ON DELETE CASCADE),
norm_table_id (FK),
lq_value INT,
calculated_at DATETIME,
calculated_by_user_id (FK NULL),
reason VARCHAR(255)                   -- "initial", "norm_table_updated", "manual_recalc"
```

### student_login_codes
Einmal-Zugangscodes pro Schüler pro Durchlauf.
```
id, student_id (FK), test_run_id (FK),
login_code CHAR(10) UNIQUE,
status ENUM('aktiv','in_bearbeitung','verbraucht','gesperrt'),
issued_at, consumed_at, reset_at,
reset_by_user_id (FK NULL),
created_at, updated_at,
UNIQUE(student_id, test_run_id)
```

> **Begründung:** Vom Vorprojekt entkoppelt – statt eines globalen `login_code` am Schüler gibt es einen Code pro Testdurchlauf. Erlaubt mehrere Erhebungen pro Schuljahr und sauberen Reset.

---

## 8. Drucksachen / Templates

### print_templates
```
id, key VARCHAR(100) UNIQUE,        -- z.B. "rueckmeldung","qr_liste","verlaufsdiagramm"
name, description, type VARCHAR(50),
is_system (bool),                    -- system-templates kann man duplizieren, aber nicht löschen
current_version_id (FK -> print_template_versions, nullable),
created_at, updated_at
```

### print_template_versions
Versionierte Vorlagen.
```
id, print_template_id (FK, ON DELETE CASCADE),
version_number INT,
html_content LONGTEXT,
css_content LONGTEXT,
variables_schema JSON,               -- welche Variablen verfügbar
notes TEXT,
created_by_user_id (FK), created_at,
UNIQUE(print_template_id, version_number)
```

### print_jobs
Asynchrone PDF-Generierungs-Aufträge.
```
id, print_template_version_id (FK),
context_type VARCHAR(50),            -- 'attempt','test_run','student','custom'
context_id BIGINT UNSIGNED,
parameters JSON,
status ENUM('pending','running','done','failed'),
error_message TEXT NULL,
output_document_id (FK NULL),
requested_by_user_id (FK),
requested_at, started_at, finished_at
```

### generated_documents
Fertige PDFs.
```
id, file_name, file_path,
mime_type VARCHAR(50), size_bytes BIGINT,
includes_clearnames (bool),
sha256 CHAR(64),
expires_at DATETIME NULL,            -- Default: now+30d
created_by_user_id (FK),
created_at
```

---

## 9. Mail

### mail_settings
```
id (=1, Singleton),
smtp_host, smtp_port, smtp_username, smtp_password_encrypted,
smtp_encryption ENUM('tls','starttls','none'),
from_address, from_name,
reply_to,
is_active,
updated_by_user_id (FK), updated_at
```

### mail_messages
```
id, to_addresses TEXT, cc TEXT, bcc TEXT,
subject, body_html LONGTEXT, body_text LONGTEXT,
status ENUM('queued','sent','failed','bounced'),
error_message TEXT,
related_entity_type VARCHAR(50), related_entity_id BIGINT UNSIGNED,
includes_clearnames (bool),
sent_by_user_id (FK), sent_at, created_at
```

### mail_attachments
```
id, mail_message_id (FK, ON DELETE CASCADE),
generated_document_id (FK NULL),
file_name, mime_type, size_bytes,
created_at
```

---

## 10. Backup

### backup_targets
```
id, name, type ENUM('local','sftp','s3'),
config_encrypted JSON,               -- Verbindungsdaten verschlüsselt
encryption_password_encrypted VARBINARY(512),  -- Backup-Verschlüsselungspasswort
retention_daily INT DEFAULT 7,
retention_weekly INT DEFAULT 4,
retention_monthly INT DEFAULT 12,
is_active,
created_at, updated_at
```

### backup_runs
```
id, backup_target_id (FK),
trigger ENUM('manual','scheduled'),
status ENUM('running','success','failed'),
started_at, finished_at,
file_name, size_bytes BIGINT,
sha256 CHAR(64),
includes_db (bool), includes_files (bool), includes_config (bool),
error_message TEXT,
triggered_by_user_id (FK NULL)
```

---

## 11. Import

### import_sources
Konfiguration der Importquellen (z.B. SVWS-Endpunkt).
```
id, key VARCHAR(50) UNIQUE,         -- 'schild_csv','svws_api','manual'
name, type VARCHAR(50),
config_encrypted JSON NULL,          -- z.B. SVWS API URL/Token
is_active,
created_at, updated_at
```

### import_jobs
Mehrstufiger Importprozess.
```
id, import_source_id (FK), school_year_id (FK),
group_type ENUM('klasse','kurs'),
filename VARCHAR(255) NULL,
status ENUM('uploaded','validated','diff_ready','committed','aborted','failed'),
mapping JSON,                        -- Spalten-Mapping
stats JSON,                          -- {total, valid, errors, archive_candidates, ...}
started_by_user_id (FK),
started_at, validated_at, committed_at,
error_message TEXT
```

### import_diff_entries
Einzelne Diff-Zeilen je Importjob.
```
id, import_job_id (FK, ON DELETE CASCADE),
row_number INT,
external_student_id VARCHAR(50),
action ENUM('create','update','archive','skip','error'),
matched_student_id (FK NULL),
payload JSON,                        -- die Roh-Eingabe (verschlüsselt wo Klarname)
errors JSON,
admin_decision ENUM('confirm','exclude') DEFAULT 'confirm',
admin_decision_reason TEXT NULL,
created_at
```

### student_imports
Abgeschlossener Importlauf, für Auditing.
```
id, import_job_id (FK NULL),
school_year_id (FK NULL),
filename, source_key,
rows_total, rows_imported, rows_updated, rows_archived, rows_skipped,
imported_by_user_id (FK), imported_at
```

---

## 12. Audit & Logs

### audit_logs
```
id, actor_type ENUM('user','system','student','external'),
actor_user_id (FK NULL),
action VARCHAR(100),
entity_type VARCHAR(100), entity_id BIGINT UNSIGNED NULL,
context JSON,                        -- z.B. {test_run_id, ...}
includes_clearnames (bool),          -- für DSGVO-relevante Aktionen
ip_address VARCHAR(45), user_agent VARCHAR(255),
created_at,
INDEX(actor_user_id, created_at),
INDEX(entity_type, entity_id),
INDEX(action, created_at)
```

### export_logs
```
id, export_type VARCHAR(50),
context_type VARCHAR(50), context_id BIGINT UNSIGNED,
generated_document_id (FK NULL),
includes_clearnames (bool),
triggered_by_user_id (FK), created_at
```

### login_logs
```
id, user_id (FK NULL),               -- NULL bei unbekanntem Benutzernamen
username_attempted VARCHAR(100),
ip_address VARCHAR(45), user_agent VARCHAR(255),
result ENUM('success','wrong_password','unknown_user','locked','two_factor_failed','two_factor_required'),
created_at
```

---

## 13. Wichtige Änderungen gegenüber dem Vorprojekt

| Bereich | Alt | Neu | Begründung |
|---------|-----|-----|-----------|
| Schüler-Identität | An `school_year_id` gebunden | Stabil über Laufbahn (`students` ohne `school_year_id`), Zuordnung über `student_enrollments` | Längsschnitt 5–10/8 Jahre |
| Login-Code | Globaler Einmalcode am Schüler | Pro Schüler+Testdurchlauf separater Code (`student_login_codes`) | Mehrere Erhebungen pro Jahr möglich |
| Normtabellen | Nur `raw_score → m/w` | 3D: `Schulstufe × Geschlecht × Parallelform → LQ`, dazu `parallel_form` an `questionnaires` | SLS-konform |
| LQ am Versuch | Ein einziger Wert | `score_raw` (immutable), `lq_at_submission` (Snapshot), `lq_current` (re-berechenbar) + `attempt_lq_history` | Norm-Updates rückwirkend möglich, aber nachvollziehbar |
| Klarnamen-Krypto | Passwort direkt = Schlüssel; Wechsel = alle Daten neu verschlüsseln | Envelope Encryption (DEK + n Wraps + Recovery-Key) | Multi-User, schneller Wechsel, Recovery |
| Permissions | Hartkodierte Rollen `admin`/`lehrkraft` | Granulare `permissions` + frei anlegbare `user_groups` + Scopes + Overrides | Skalierbar, schulindividuell |
| Erhebungstyp | Implizit am Testdurchlauf | Eigene Tabelle `assessment_types` | Vor-/Nachtest-Vergleiche, Filter |
| Förderbedarf | Nicht modelliert | `support_thresholds` konfigurierbar | Kernfeature der Längsschnittauswertung |
| Druck-Templates | LaTeX-Strings im `feedback_set_ranges` | Eigene `print_templates` mit Versionen + HTML/CSS + WYSIWYG | Bearbeitbar im UI, alle Drucksachen, reproduzierbar |
| PDF-Generierung | Keine (nur LaTeX-Download) | Async via Queue + Gotenberg, `print_jobs`/`generated_documents` | Serverseitig, alles als PDF |
| Backup | Nicht modelliert | `backup_targets`, `backup_runs` | Pflicht für produktiven Betrieb |
| Import | Direkter Commit | `import_jobs` mit Phasen (Validate → Diff → Commit), `import_diff_entries` mit Archivkandidaten | Geführter Assistent, Dry-Run, Diff |
| Mail | Nicht vorhanden | `mail_settings`, `mail_messages`, `mail_attachments` | SMTP-Versand mit Protokoll |
| Audit | Eine Tabelle, eher grob | Erweitert um `includes_clearnames`, IP, User-Agent + dedizierte `login_logs` | DSGVO-relevant |

---

## 14. Indexierung (kritische Pfade)

- `students(external_student_id, external_id_source)` — UNIQUE, Match beim Import
- `students(student_code)` — UNIQUE, Anzeige
- `student_login_codes(login_code)` — UNIQUE, Schüler-Login
- `student_enrollments(student_id, school_year_id)` — UNIQUE
- `student_group_memberships(student_id, learning_group_id)` — UNIQUE
- `test_attempts(student_id, test_run_id)` — INDEX (Längsschnitt-Query)
- `attempt_answers(test_attempt_id, question_id)` — UNIQUE
- `audit_logs(actor_user_id, created_at)` — INDEX
- `audit_logs(entity_type, entity_id)` — INDEX

---

## 15. Datenflüsse (Beispiele)

### 15.1 Längsschnitt eines Schülers laden
```sql
SELECT
    ta.id, ta.score_raw, ta.lq_current, ta.lq_at_submission,
    ta.submitted_at,
    tr.name AS test_run_name,
    at.label AS assessment_type,
    sy.label AS school_year,
    lg.name AS learning_group,
    nt.name AS norm_table_name
FROM test_attempts ta
JOIN test_runs tr ON tr.id = ta.test_run_id
JOIN assessment_types at ON at.id = tr.assessment_type_id
JOIN school_years sy ON sy.id = tr.school_year_id
LEFT JOIN norm_tables nt ON nt.id = ta.norm_table_id
LEFT JOIN student_group_memberships sgm
       ON sgm.student_id = ta.student_id
      AND sgm.school_year_id = tr.school_year_id
LEFT JOIN learning_groups lg ON lg.id = sgm.learning_group_id
WHERE ta.student_id = ?
  AND ta.status IN ('abgegeben','zeit_abgelaufen')
ORDER BY ta.submitted_at;
```

### 15.2 Förderbedarf-Liste
Iteriert über aktive `support_thresholds`, wendet jedes Kriterium auf die letzten Versuche jedes aktiven Schülers an, vereinigt die Treffer mit der höchsten `severity`.

### 15.3 Importassistent – Diff-Phase
```
für jede Zeile aus dem Import:
    suche student WHERE external_student_id=row.id AND external_id_source=source
    wenn gefunden:
        wenn Daten unverändert → action=skip
        sonst → action=update mit Δ
    sonst:
        action=create

aktive Studenten ohne Match in Importzeilen UND deren letztes Enrollment im aktuellen Schuljahr:
    action=archive (Begründung: "nicht in aktuellem Import")
```

### 15.4 Klarnamen entsperren in Session
```
1. User gibt Klarnamen-Passwort ein
2. Lade key_wraps WHERE user_id=? AND wrap_type='user_password' AND active EncKey
3. KEK = Argon2id(password, kdf_salt, kdf_params)
4. DEK = AES-Unwrap(wrapped_dek, KEK)
5. Verifikation: hash(DEK) == verification_hash
6. DEK in Session speichern (nur memory-Cache, nicht persistent)
7. Audit: "clearname.unlock"
```

---

## 16. Offene Detailfragen für den Permission-Katalog

Werden im nächsten Dokument (`03-permissions.md`) konkretisiert:

- Vollständige Liste der Permission-Keys mit Beschreibung
- Welche sind `is_scopeable`?
- Welche Permissions bekommen die Default-Klassen (Admin / Schulleitung / Sekretariat / Lehrkraft) initial?
- Welche Permissions sind 2FA-Pflicht (z.B. `clearname.unlock`, `students.delete`)?
