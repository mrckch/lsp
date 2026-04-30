<?php

declare(strict_types=1);

namespace App\Domain\Permission;

/**
 * Statischer Permission-Katalog (Single Source of Truth).
 *
 * Jeder Eintrag: [key, area, description, is_scopeable, requires_two_factor]
 *
 * Bezug: docs/03-permissions.md
 */
final class PermissionCatalog
{
    /**
     * @return list<array{key:string, area:string, description:string, is_scopeable:bool, requires_two_factor:bool}>
     */
    public static function all(): array
    {
        return [
            // School
            ['key' => 'school_years.view',                       'area' => 'school',     'description' => 'Schuljahre ansehen',                                  'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'school_years.manage',                     'area' => 'school',     'description' => 'Schuljahre anlegen/ändern/archivieren',               'is_scopeable' => false, 'requires_two_factor' => false],

            // Learning Groups
            ['key' => 'learning_groups.view',                    'area' => 'school',     'description' => 'Lerngruppen ansehen',                                 'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'learning_groups.manage',                  'area' => 'school',     'description' => 'Lerngruppen anlegen/ändern/löschen',                  'is_scopeable' => true,  'requires_two_factor' => false],

            // Students
            ['key' => 'students.view',                           'area' => 'students',   'description' => 'Schülerliste & Stammdaten',                           'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'students.view_clearname',                 'area' => 'students',   'description' => 'Klarnamen sehen',                                     'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'students.manage',                         'area' => 'students',   'description' => 'Schüler manuell anlegen/ändern',                      'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'students.archive',                        'area' => 'students',   'description' => 'Schüler ins Archiv verschieben',                      'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'students.unarchive',                      'area' => 'students',   'description' => 'Aus Archiv reaktivieren',                             'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'students.delete',                         'area' => 'students',   'description' => 'Schüler endgültig löschen',                           'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'students.export',                         'area' => 'students',   'description' => 'Schülerdaten exportieren (ohne Klarnamen)',           'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'students.export_with_clearname',          'area' => 'students',   'description' => 'Export mit Klarnamen',                                'is_scopeable' => true,  'requires_two_factor' => true],

            // Import
            ['key' => 'import.run',                              'area' => 'import',     'description' => 'Importassistent starten',                             'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'import.commit_archive',                   'area' => 'import',     'description' => 'Archivierung fehlender SuS bestätigen',               'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'import.sources.manage',                   'area' => 'import',     'description' => 'Importquellen konfigurieren',                         'is_scopeable' => false, 'requires_two_factor' => true],

            // Test config
            ['key' => 'questionnaires.view',                     'area' => 'test_config', 'description' => 'Fragebögen ansehen',                                  'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'questionnaires.manage',                   'area' => 'test_config', 'description' => 'Fragebögen anlegen/ändern/importieren',               'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'norm_tables.view',                        'area' => 'test_config', 'description' => 'Normtabellen ansehen',                                'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'norm_tables.manage',                      'area' => 'test_config', 'description' => 'Normtabellen anlegen/ändern/importieren',             'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'feedback_sets.view',                      'area' => 'test_config', 'description' => 'Rückmeldesets ansehen',                               'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'feedback_sets.manage',                    'area' => 'test_config', 'description' => 'Rückmeldesets anlegen/ändern',                        'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'notice_texts.view',                       'area' => 'test_config', 'description' => 'Hinweistexte ansehen',                                'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'notice_texts.manage',                     'area' => 'test_config', 'description' => 'Hinweistexte anlegen/ändern',                         'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'assessment_types.manage',                 'area' => 'test_config', 'description' => 'Erhebungstypen pflegen',                              'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'support_thresholds.manage',               'area' => 'test_config', 'description' => 'Förderbedarfsschwellen pflegen',                      'is_scopeable' => false, 'requires_two_factor' => false],

            // Test runs
            ['key' => 'test_runs.view',                          'area' => 'test_runs',  'description' => 'Testdurchläufe ansehen',                              'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'test_runs.create',                        'area' => 'test_runs',  'description' => 'Eigene Testdurchläufe erstellen',                     'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'test_runs.manage_own',                    'area' => 'test_runs',  'description' => 'Eigene Testdurchläufe ändern',                        'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'test_runs.manage_all',                    'area' => 'test_runs',  'description' => 'Fremde Testdurchläufe ändern',                        'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'test_runs.change_status',                 'area' => 'test_runs',  'description' => 'Status ändern',                                       'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'test_runs.delete',                        'area' => 'test_runs',  'description' => 'Testdurchlauf löschen',                               'is_scopeable' => true,  'requires_two_factor' => true],
            ['key' => 'test_runs.security.view',                 'area' => 'test_runs',  'description' => 'Lehrkraft-/Klarnamenscode sehen',                     'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'test_runs.security.regenerate',           'area' => 'test_runs',  'description' => 'Codes neu generieren',                                'is_scopeable' => true,  'requires_two_factor' => false],

            // Login codes
            ['key' => 'login_codes.view',                        'area' => 'test_runs',  'description' => 'Login-Codes anzeigen',                                'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'login_codes.reset',                       'area' => 'test_runs',  'description' => 'Code zurücksetzen, Versuch neu starten',              'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'login_codes.lock',                        'area' => 'test_runs',  'description' => 'Schüler sperren',                                     'is_scopeable' => true,  'requires_two_factor' => false],

            // Attempts
            ['key' => 'attempts.view',                           'area' => 'attempts',   'description' => 'Versuche ansehen',                                    'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'attempts.view_answers',                   'area' => 'attempts',   'description' => 'Einzelantworten ansehen',                             'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'attempts.reset',                          'area' => 'attempts',   'description' => 'Versuch zurücksetzen',                                'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'attempts.recalculate_lq',                 'area' => 'attempts',   'description' => 'LQ neu berechnen',                                    'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'attempts.export',                         'area' => 'attempts',   'description' => 'Ergebnisse exportieren (ohne Klarnamen)',             'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'attempts.export_with_clearname',          'area' => 'attempts',   'description' => 'Ergebnisse mit Klarnamen exportieren',                'is_scopeable' => true,  'requires_two_factor' => true],

            // Analytics
            ['key' => 'analytics.student_history',               'area' => 'analytics',  'description' => 'Längsschnittansicht eines Schülers',                   'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'analytics.cohort_overview',               'area' => 'analytics',  'description' => 'Aggregierte Kohorten-Auswertung',                     'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'analytics.support_list',                  'area' => 'analytics',  'description' => 'Förderbedarfs-Liste',                                 'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'analytics.school_overview',               'area' => 'analytics',  'description' => 'Schul-/Jahrgangs-Vergleich',                          'is_scopeable' => false, 'requires_two_factor' => false],

            // Print
            ['key' => 'print.generate',                          'area' => 'print',      'description' => 'PDF-Erzeugung anstoßen',                              'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'print.generate_with_clearname',           'area' => 'print',      'description' => 'PDF mit Klarnamen erzeugen',                          'is_scopeable' => true,  'requires_two_factor' => true],
            ['key' => 'print.download',                          'area' => 'print',      'description' => 'Generiertes PDF herunterladen',                       'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'print.templates.view',                    'area' => 'print',      'description' => 'Druckvorlagen ansehen',                               'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'print.templates.manage',                  'area' => 'print',      'description' => 'Druckvorlagen bearbeiten/versionieren',               'is_scopeable' => false, 'requires_two_factor' => false],

            // Mail
            ['key' => 'mail.send',                               'area' => 'mail',       'description' => 'Mails versenden (ohne Klarnamen)',                    'is_scopeable' => true,  'requires_two_factor' => false],
            ['key' => 'mail.send_with_clearname',                'area' => 'mail',       'description' => 'Mails mit Klarnamen-Inhalten versenden',              'is_scopeable' => true,  'requires_two_factor' => true],
            ['key' => 'mail.settings.manage',                    'area' => 'mail',       'description' => 'SMTP-Einstellungen pflegen',                          'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'mail.log.view',                           'area' => 'mail',       'description' => 'Mailprotokoll ansehen',                               'is_scopeable' => false, 'requires_two_factor' => false],

            // Clearname / Crypto
            ['key' => 'clearname.unlock',                        'area' => 'clearname',  'description' => 'Klarnamen für die Sitzung entsperren',                'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'clearname.password.change',               'area' => 'clearname',  'description' => 'Eigenes Klarnamen-Passwort ändern',                   'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'clearname.password.rotate_all',           'area' => 'clearname',  'description' => 'Schulweite Rotation erzwingen',                       'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'clearname.recovery.use',                  'area' => 'clearname',  'description' => 'Mit Recovery-Key Zugang wiederherstellen',            'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'clearname.recovery.regenerate',           'area' => 'clearname',  'description' => 'Recovery-Key neu erzeugen',                           'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'clearname.password.provision',            'area' => 'clearname',  'description' => 'Initialen Klarnamen-Zugang für anderen User anlegen', 'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'clearname.password.revoke',               'area' => 'clearname',  'description' => 'Klarnamen-Zugang eines Users entziehen',              'is_scopeable' => false, 'requires_two_factor' => true],

            // User management
            ['key' => 'users.view',                              'area' => 'users',      'description' => 'Benutzerliste ansehen',                               'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'users.manage',                            'area' => 'users',      'description' => 'Benutzer anlegen/ändern/deaktivieren',                'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'users.delete',                            'area' => 'users',      'description' => 'Benutzer löschen',                                    'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'users.reset_password',                    'area' => 'users',      'description' => 'Passwort zurücksetzen',                               'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'users.print_credentials',                 'area' => 'users',      'description' => 'Zugangsdaten ausdrucken',                             'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'user_groups.manage',                      'area' => 'users',      'description' => 'Benutzerklassen anlegen/ändern',                      'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'permissions.assign',                      'area' => 'users',      'description' => 'Permissions Klassen oder Usern zuweisen',             'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'scopes.assign',                           'area' => 'users',      'description' => 'Lerngruppen-Scopes zuweisen',                         'is_scopeable' => false, 'requires_two_factor' => true],

            // System
            ['key' => 'system.settings.view',                    'area' => 'system',     'description' => 'Systemkonfiguration ansehen',                         'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'system.settings.manage',                  'area' => 'system',     'description' => 'Systemkonfiguration ändern',                          'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'system.backup.run',                       'area' => 'system',     'description' => 'Backup manuell ausführen',                            'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'system.backup.targets.manage',            'area' => 'system',     'description' => 'Backup-Ziele konfigurieren',                          'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'system.backup.download',                  'area' => 'system',     'description' => 'Backup herunterladen',                                'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'system.backup.restore',                   'area' => 'system',     'description' => 'Backup zurückspielen (zerstörerisch)',                'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'system.audit.view',                       'area' => 'system',     'description' => 'Audit-Log einsehen',                                  'is_scopeable' => false, 'requires_two_factor' => false],
            ['key' => 'system.audit.export',                     'area' => 'system',     'description' => 'Audit-Log exportieren',                               'is_scopeable' => false, 'requires_two_factor' => true],
            ['key' => 'system.health.view',                      'area' => 'system',     'description' => 'Health/Status-Dashboard',                             'is_scopeable' => false, 'requires_two_factor' => false],
        ];
    }

    /**
     * Default-Permissions pro System-Benutzerklasse.
     *
     * @return array<string, list<string>>
     */
    public static function defaultsForGroups(): array
    {
        $admin = array_map(fn ($p) => $p['key'], self::all());

        $schulleitung = [
            'school_years.view', 'school_years.manage',
            'learning_groups.view', 'learning_groups.manage',
            'students.view', 'students.view_clearname', 'students.manage',
            'students.archive', 'students.unarchive', 'students.export', 'students.export_with_clearname',
            'questionnaires.view', 'norm_tables.view', 'feedback_sets.view', 'notice_texts.view',
            'support_thresholds.manage',
            'test_runs.view', 'test_runs.create', 'test_runs.manage_own', 'test_runs.manage_all',
            'test_runs.change_status', 'test_runs.security.view', 'test_runs.security.regenerate',
            'login_codes.view', 'login_codes.reset', 'login_codes.lock',
            'attempts.view', 'attempts.view_answers', 'attempts.reset',
            'attempts.export', 'attempts.export_with_clearname',
            'analytics.student_history', 'analytics.cohort_overview',
            'analytics.support_list', 'analytics.school_overview',
            'print.generate', 'print.generate_with_clearname', 'print.download',
            'print.templates.view',
            'mail.send', 'mail.send_with_clearname', 'mail.log.view',
            'clearname.unlock', 'clearname.password.change',
            'users.view',
            'system.settings.view', 'system.audit.view', 'system.health.view',
        ];

        $sekretariat = [
            'school_years.view',
            'learning_groups.view', 'learning_groups.manage',
            'students.view', 'students.view_clearname', 'students.manage',
            'students.archive', 'students.unarchive', 'students.export',
            'import.run', 'import.commit_archive',
            'test_runs.view',
            'login_codes.view', 'login_codes.lock',
            'attempts.view', 'attempts.export',
            'print.generate', 'print.download',
            'mail.send',
            'clearname.unlock', 'clearname.password.change',
        ];

        $lehrkraft = [
            'school_years.view',
            'learning_groups.view',
            'students.view', 'students.view_clearname',
            'questionnaires.view', 'norm_tables.view', 'feedback_sets.view', 'notice_texts.view',
            'test_runs.view', 'test_runs.create', 'test_runs.manage_own', 'test_runs.change_status',
            'test_runs.security.view', 'test_runs.security.regenerate',
            'login_codes.view', 'login_codes.reset', 'login_codes.lock',
            'attempts.view', 'attempts.view_answers', 'attempts.reset',
            'attempts.export', 'attempts.export_with_clearname',
            'analytics.student_history', 'analytics.cohort_overview', 'analytics.support_list',
            'print.generate', 'print.generate_with_clearname', 'print.download',
            'mail.send', 'mail.send_with_clearname',
            'clearname.unlock', 'clearname.password.change',
        ];

        return [
            'Admin' => $admin,
            'Schulleitung' => $schulleitung,
            'Sekretariat' => $sekretariat,
            'Lehrkraft' => $lehrkraft,
        ];
    }
}
