# LSP – Berechtigungskatalog

**Stand:** 2026-04-29
**Bezug:** Konkretisiert die Permission-Strukturen aus [02-datenmodell.md](02-datenmodell.md) Abschnitt 3.

---

## 1. Modell

### 1.1 Effektive Berechtigungen
```
effective_permissions(user) =
    (Σ permissions aller user_groups, in denen user Mitglied ist)
    ∪ user_permission_overrides[mode='grant']
    ∖ user_permission_overrides[mode='revoke']
```

### 1.2 Scope
- Permissions mit `is_scopeable = 1` sind **lerngruppen-bezogen** anwendbar.
- Wenn ein User keine `user_scope_assignments` hat → **ungescoped** (sieht alle Lerngruppen).
- Wenn ein User Einträge in `user_scope_assignments` hat → **eingeschränkt auf diese Lerngruppen**.
- Scope filtert: jede Liste/Detail-Anfrage wird transparent durch den Scope geprüft.
- `is_scopeable = 0` → Permission greift global (z. B. Backups, Systemkonfiguration).

### 1.3 Schritte beim Permission-Check
```
canDo(user, permission_key, target_entity?):
    1. Liefere effective_permissions(user)
    2. Wenn permission_key nicht enthalten → false
    3. Wenn permission scopeable und target_entity gegeben:
        a. Bestimme learning_group_id(s) des Targets
        b. Wenn user ungescoped → true
        c. Sonst: prüfe Schnittmenge mit user_scope_assignments
    4. true
```

### 1.4 2FA-Pflicht
Bestimmte Permissions erfordern, dass der User in der aktuellen Session eine **2FA-Bestätigung** durchgeführt hat (Re-Auth-Schwelle, z. B. innerhalb der letzten 15 Minuten). Markiert mit ⚠.

---

## 2. Permission-Katalog

Spalten:
- **Key**: technischer Bezeichner
- **Beschreibung**: was die Permission erlaubt
- **Scope**: ob `is_scopeable` (✓ lerngruppen-scope-fähig)
- **2FA**: ob 2FA-bestätigte Session erforderlich
- **A / SL / Sek / L**: Default-Zuweisung an die System-Klassen Admin / Schulleitung / Sekretariat / Lehrkraft (✓ = vergeben)

### 2.1 Stammdaten – Schule

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `school_years.view` | Schuljahre ansehen | – | – | ✓ | ✓ | ✓ | ✓ |
| `school_years.manage` | Schuljahre anlegen/ändern/archivieren | – | – | ✓ | ✓ | – | – |

### 2.2 Stammdaten – Lerngruppen

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `learning_groups.view` | Lerngruppen ansehen | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `learning_groups.manage` | Lerngruppen anlegen/ändern/löschen | ✓ | – | ✓ | ✓ | ✓ | – |

### 2.3 Stammdaten – Schüler

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `students.view` | Schülerliste & Stammdaten (ohne Klarnamen) | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `students.view_clearname` | Klarnamen sehen (zusätzlich zu Krypto-Unlock) | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `students.manage` | Schüler manuell anlegen/ändern | ✓ | – | ✓ | ✓ | ✓ | – |
| `students.archive` | Schüler ins Archiv verschieben | ✓ | – | ✓ | ✓ | ✓ | – |
| `students.unarchive` | Aus Archiv reaktivieren | ✓ | – | ✓ | ✓ | ✓ | – |
| `students.delete` | Schüler endgültig löschen | – | ⚠ | ✓ | – | – | – |
| `students.export` | Schüler-Stammdaten exportieren | ✓ | – | ✓ | ✓ | ✓ | – |
| `students.export_with_clearname` | Export mit Klarnamen | ✓ | ⚠ | ✓ | ✓ | – | – |

### 2.4 Import

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `import.run` | Importassistent starten | – | – | ✓ | – | ✓ | – |
| `import.commit_archive` | Bestätigen, dass fehlende SuS archiviert werden | – | ⚠ | ✓ | – | ✓ | – |
| `import.sources.manage` | Importquellen konfigurieren (z. B. SVWS-Token) | – | ⚠ | ✓ | – | – | – |

### 2.5 Test-Konfiguration

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `questionnaires.view` | Fragebögen ansehen | – | – | ✓ | ✓ | – | ✓ |
| `questionnaires.manage` | Fragebögen anlegen/ändern/importieren | – | – | ✓ | – | – | – |
| `norm_tables.view` | Normtabellen ansehen | – | – | ✓ | ✓ | – | ✓ |
| `norm_tables.manage` | Normtabellen anlegen/ändern/importieren | – | – | ✓ | – | – | – |
| `feedback_sets.view` | Rückmeldesets ansehen | – | – | ✓ | ✓ | – | ✓ |
| `feedback_sets.manage` | Rückmeldesets anlegen/ändern | – | – | ✓ | – | – | – |
| `notice_texts.view` | Hinweistexte ansehen | – | – | ✓ | ✓ | – | ✓ |
| `notice_texts.manage` | Hinweistexte anlegen/ändern | – | – | ✓ | – | – | – |
| `assessment_types.manage` | Erhebungs-Typen pflegen | – | – | ✓ | – | – | – |
| `support_thresholds.manage` | Förderbedarfs-Schwellen pflegen | – | – | ✓ | ✓ | – | – |

### 2.6 Erhebungen (Testdurchläufe)

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `test_runs.view` | Testdurchläufe ansehen | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `test_runs.create` | Eigene Testdurchläufe erstellen | ✓ | – | ✓ | ✓ | – | ✓ |
| `test_runs.manage_own` | Eigene Testdurchläufe ändern | ✓ | – | ✓ | ✓ | – | ✓ |
| `test_runs.manage_all` | Fremde Testdurchläufe ändern | ✓ | – | ✓ | ✓ | – | – |
| `test_runs.change_status` | Status ändern (aktiv/pausiert/abgeschlossen) | ✓ | – | ✓ | ✓ | – | ✓ |
| `test_runs.delete` | Testdurchlauf löschen | ✓ | ⚠ | ✓ | – | – | – |
| `test_runs.security.view` | Lehrkraft-/Klarnamenscode sehen | ✓ | – | ✓ | ✓ | – | ✓ |
| `test_runs.security.regenerate` | Codes neu generieren | ✓ | – | ✓ | ✓ | – | ✓ |

### 2.7 Schüler-Login-Codes

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `login_codes.view` | Login-Codes anzeigen | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `login_codes.reset` | Code zurücksetzen, Versuch neu starten | ✓ | – | ✓ | ✓ | – | ✓ |
| `login_codes.lock` | Schüler sperren | ✓ | – | ✓ | ✓ | ✓ | ✓ |

### 2.8 Versuche & Ergebnisse

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `attempts.view` | Versuche ansehen (Rohwert + LQ) | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `attempts.view_answers` | Einzelantworten ansehen | ✓ | – | ✓ | ✓ | – | ✓ |
| `attempts.reset` | Versuch zurücksetzen | ✓ | – | ✓ | ✓ | – | ✓ |
| `attempts.recalculate_lq` | LQ neu berechnen (nach Norm-Update) | ✓ | – | ✓ | ✓ | – | – |
| `attempts.export` | Ergebnisse exportieren (ohne Klarnamen) | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `attempts.export_with_clearname` | Ergebnisse mit Klarnamen exportieren | ✓ | ⚠ | ✓ | ✓ | – | ✓ |

### 2.9 Auswertung

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `analytics.student_history` | Längsschnittansicht eines Schülers | ✓ | – | ✓ | ✓ | – | ✓ |
| `analytics.cohort_overview` | Aggregierte Kohorten-Auswertung | ✓ | – | ✓ | ✓ | – | ✓ |
| `analytics.support_list` | Förderbedarfs-Liste | ✓ | – | ✓ | ✓ | – | ✓ |
| `analytics.school_overview` | Schul-/Jahrgangs-Vergleich | – | – | ✓ | ✓ | – | – |

### 2.10 Drucksachen

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `print.generate` | PDF-Erzeugung anstoßen | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `print.generate_with_clearname` | PDF mit Klarnamen erzeugen | ✓ | ⚠ | ✓ | ✓ | – | ✓ |
| `print.download` | Generiertes PDF herunterladen | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `print.templates.view` | Druckvorlagen ansehen | – | – | ✓ | ✓ | – | – |
| `print.templates.manage` | Druckvorlagen bearbeiten/versionieren | – | – | ✓ | – | – | – |

### 2.11 Mail

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `mail.send` | Mails versenden (ohne Klarnamen) | ✓ | – | ✓ | ✓ | ✓ | ✓ |
| `mail.send_with_clearname` | Mails mit Klarnamen-Inhalten versenden | ✓ | ⚠ | ✓ | ✓ | – | ✓ |
| `mail.settings.manage` | SMTP-Einstellungen pflegen | – | ⚠ | ✓ | – | – | – |
| `mail.log.view` | Mailprotokoll ansehen | – | – | ✓ | ✓ | – | – |

### 2.12 Klarnamen-Krypto

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `clearname.unlock` | Klarnamen für die Sitzung entsperren | – | – | ✓ | ✓ | ✓ | ✓ |
| `clearname.password.change` | Eigenes Klarnamen-Passwort ändern | – | – | ✓ | ✓ | ✓ | ✓ |
| `clearname.password.rotate_all` | Schulweite Rotation erzwingen | – | ⚠ | ✓ | – | – | – |
| `clearname.recovery.use` | Mit Recovery-Key Zugang wiederherstellen | – | ⚠ | ✓ | – | – | – |
| `clearname.recovery.regenerate` | Recovery-Key neu erzeugen | – | ⚠ | ✓ | – | – | – |

### 2.13 Benutzerverwaltung

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `users.view` | Benutzerliste ansehen | – | – | ✓ | ✓ | – | – |
| `users.manage` | Benutzer anlegen/ändern/deaktivieren | – | ⚠ | ✓ | – | – | – |
| `users.delete` | Benutzer löschen | – | ⚠ | ✓ | – | – | – |
| `users.reset_password` | Passwort zurücksetzen | – | ⚠ | ✓ | – | – | – |
| `users.print_credentials` | Zugangsdaten ausdrucken | – | – | ✓ | – | – | – |
| `user_groups.manage` | Benutzerklassen anlegen/ändern | – | ⚠ | ✓ | – | – | – |
| `permissions.assign` | Permissions Klassen oder Usern zuweisen | – | ⚠ | ✓ | – | – | – |
| `scopes.assign` | Lerngruppen-Scopes zuweisen | – | ⚠ | ✓ | – | – | – |

### 2.14 System

| Key | Beschreibung | Scope | 2FA | A | SL | Sek | L |
|---|---|---|---|---|---|---|---|
| `system.settings.view` | Systemkonfiguration ansehen | – | – | ✓ | ✓ | – | – |
| `system.settings.manage` | Systemkonfiguration ändern | – | ⚠ | ✓ | – | – | – |
| `system.backup.run` | Backup manuell ausführen | – | ⚠ | ✓ | – | – | – |
| `system.backup.targets.manage` | Backup-Ziele konfigurieren | – | ⚠ | ✓ | – | – | – |
| `system.backup.download` | Backup herunterladen | – | ⚠ | ✓ | – | – | – |
| `system.audit.view` | Audit-Log einsehen | – | – | ✓ | ✓ | – | – |
| `system.audit.export` | Audit-Log exportieren | – | ⚠ | ✓ | – | – | – |
| `system.health.view` | Health/Status-Dashboard | – | – | ✓ | ✓ | – | – |

---

## 3. Default-Zuweisung in der Übersicht

| System-Klasse | Charakteristik |
|---|---|
| **Admin** | Volle Rechte, einzige Klasse mit Berechtigung für System-Konfiguration, Benutzerverwaltung, Recovery-Key |
| **Schulleitung** | Schulweiter Lese- und Auswertungs-Zugriff, kein Recht für System-Setup |
| **Sekretariat** | Stammdatenpflege Schüler, Import, Druck/Mail ohne Klarnamen, kein fachlicher Zugriff auf Tests |
| **Lehrkraft** | Operativ: Tests durchführen, Ergebnisse einsehen, Rückmeldungen drucken/mailen – standardmäßig schulweit, **bei Scope-Zuweisung beschränkt auf eigene Lerngruppen** |

---

## 4. Beispiele für User-Overrides

### 4.1 Klassenlehrer mit Sonderaufgabe
- Mitglied von Klasse "Lehrkraft"
- Override: `+analytics.school_overview` → darf zusätzlich Schul-Vergleiche sehen

### 4.2 Beratungslehrer ohne Klarnamen-Recht
- Mitglied der Klasse "Lehrkraft"
- Override: `−clearname.unlock` → darf trotz Lehrer-Rolle keine Klarnamen entsperren

### 4.3 Sekretariat mit Zugriff auf Audit-Log
- Mitglied der Klasse "Sekretariat"
- Override: `+system.audit.view`

---

## 5. Scope-Default für Lehrkräfte

Vom Admin steuerbar im UI:

```
Beim Anlegen einer Lehrkraft:
  Frage: "Welche Lerngruppen darf diese Lehrkraft sehen?"
    [ ] alle (Standard)
    [x] nur ausgewählte:  __5a__ __5b__ __6c__ ...

Wenn "nur ausgewählte":
  → Einträge in user_scope_assignments
  → Lehrkraft sieht in students.view, attempts.view, analytics.* nur
    Datensätze, die mit diesen Lerngruppen verknüpft sind
```

Pro Schuljahr neu prüfbar (z. B. bei Klassenwechsel der Lehrkraft).

---

## 6. 2FA-Pflicht-Schwelle (Re-Auth)

Operationen mit ⚠ erfordern, dass der User innerhalb der letzten **15 Minuten** eine 2FA-Bestätigung gemacht hat. Wenn nicht: UI fordert TOTP erneut an, bevor die Aktion ausgeführt wird.

Wenn der User **kein 2FA** aktiviert hat:
- Operationen mit ⚠ sind **nicht aufrufbar** für Permissions-Klassen, in denen 2FA optional ist
- Admin kann pro Klasse setzen: `force_two_factor = true` → User muss 2FA einrichten, sonst wird Login eingeschränkt

---

## 7. Audit-Mapping

Jede Permission-getriggerte Aktion erzeugt einen `audit_logs`-Eintrag mit:
- `actor_user_id`
- `action` = Permission-Key (z. B. `clearname.unlock`)
- `entity_type` / `entity_id` (z. B. `student/4711`)
- `includes_clearnames` = true für alle `*_with_clearname` und `clearname.*`-Aktionen
- `context` mit relevanten IDs (test_run_id, export_id, ...)

---

## 8. Implementierungshinweise

- Nutze `spatie/laravel-permission` für die `permissions` + `group_permissions` + `user_permission_overrides`-Logik (Override-Layer ergänzen wir).
- Scopes als eigener Service `ScopeFilter`, in jedem Repository-Read als Where-Clause integriert.
- 2FA-Re-Auth-Status als Session-Flag `last_2fa_at`, vor jeder ⚠-Aktion vom Middleware geprüft.
- Permission-Katalog als PHP-Konstanten + JSON für Frontend-Permission-UI (Anzeige der Beschreibungen).
