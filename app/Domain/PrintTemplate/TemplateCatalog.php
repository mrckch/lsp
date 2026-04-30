<?php

declare(strict_types=1);

namespace App\Domain\PrintTemplate;

/**
 * Bekannte Template-Typen mit ihren verfügbaren Variablen + Beispiel-Daten
 * für die Vorschau.
 *
 * Das Schema ist die Single Source of Truth für:
 *  - Editor-Variablen-Hilfe (welche {{...}} sind erlaubt)
 *  - PDF-Vorschau mit realistischen Beispieldaten
 *  - Doku
 */
final class TemplateCatalog
{
    /**
     * @return array<string, array{label:string, description:string, variables:array<string,string>, sample:array<string,mixed>}>
     */
    public static function types(): array
    {
        return [
            'student_feedback' => [
                'label' => 'Rückmeldebogen (pro Schüler)',
                'description' => 'Persönliche Rückmeldung mit Rohwert, LQ und Einordnung.',
                'variables' => [
                    'school_name' => 'Name der Schule',
                    'date' => 'Erstellungsdatum',
                    'student_name' => 'Vor- und Nachname',
                    'student_code' => 'Anonymer Schülercode',
                    'group_name' => 'Klasse oder Kurs',
                    'school_year' => 'Schuljahr',
                    'test_run_name' => 'Name der Erhebung',
                    'assessment_type' => 'Typ (Eingangstest, ...)',
                    'score_raw' => 'Rohwert (Anzahl korrekter Antworten)',
                    'lq' => 'Lesequotient',
                    'feedback_text' => 'Passender Rückmeldetext aus dem Set',
                ],
                'sample' => [
                    'school_name' => 'Beispielschule',
                    'date' => '30.04.2026',
                    'student_name' => 'Anna Müller',
                    'student_code' => 'BSP-00042',
                    'group_name' => '6a',
                    'school_year' => '2025/26',
                    'test_run_name' => 'Eingangstest 6a',
                    'assessment_type' => 'Eingangstest',
                    'score_raw' => 64,
                    'lq' => 102,
                    'feedback_text' => 'Anna liest sicher und im erwarteten Bereich für ihre Klassenstufe.',
                ],
            ],

            'login_codes' => [
                'label' => 'QR-Code-Liste / Zugangsdaten',
                'description' => 'Liste der Login-Codes pro Lerngruppe für die Test-Durchführung.',
                'variables' => [
                    'school_name' => 'Name der Schule',
                    'run_name' => 'Name der Erhebung',
                    'group_name' => 'Lerngruppe',
                    'date' => 'Datum',
                    'students' => 'Liste der SuS (Schleife im Template)',
                ],
                'sample' => [
                    'school_name' => 'Beispielschule',
                    'run_name' => 'Eingangstest 6a',
                    'group_name' => '6a',
                    'date' => '30.04.2026',
                    'students' => [
                        ['code' => 'ABCD2345EF', 'name' => 'Anna Müller'],
                        ['code' => 'GHJK7P89QR', 'name' => 'Ben Schmidt'],
                        ['code' => 'STUV234567', 'name' => 'Carla Becker'],
                    ],
                ],
            ],

            'student_history' => [
                'label' => 'Verlaufsdiagramm (Längsschnitt)',
                'description' => 'Persönlicher LQ-Verlauf eines Schülers über die Schullaufbahn.',
                'variables' => [
                    'school_name' => 'Name der Schule',
                    'student_name' => 'Vor- und Nachname',
                    'student_code' => 'Schülercode',
                    'history' => 'Liste aller Erhebungen mit LQ',
                    'class_avg' => 'Klassen-Durchschnitt pro Erhebung',
                    'date' => 'Erstellungsdatum',
                ],
                'sample' => [
                    'school_name' => 'Beispielschule',
                    'student_name' => 'Anna Müller',
                    'student_code' => 'BSP-00042',
                    'date' => '30.04.2026',
                    'history' => [
                        ['date' => '2024-09-15', 'label' => 'Eingangstest Kl. 5', 'lq' => 92],
                        ['date' => '2025-05-20', 'label' => 'Abschlusstest Kl. 5', 'lq' => 98],
                        ['date' => '2025-09-10', 'label' => 'Eingangstest Kl. 6', 'lq' => 100],
                        ['date' => '2026-04-28', 'label' => 'Zwischenerhebung Kl. 6', 'lq' => 102],
                    ],
                ],
            ],

            'support_list' => [
                'label' => 'Förderbedarfs-Liste',
                'description' => 'Schüler, die mind. eine konfigurierte Schwelle erreichen.',
                'variables' => [
                    'school_name' => 'Name der Schule',
                    'date' => 'Erstellungsdatum',
                    'rows' => 'Liste {student, group, lq, severity, threshold_name}',
                ],
                'sample' => [
                    'school_name' => 'Beispielschule',
                    'date' => '30.04.2026',
                    'rows' => [
                        ['student' => 'Bob Schmidt', 'group' => '6a', 'lq' => 68, 'severity' => 'foerderbedarf', 'threshold_name' => 'LQ < 70'],
                        ['student' => 'Carla Becker', 'group' => '6b', 'lq' => 82, 'severity' => 'auffaellig', 'threshold_name' => 'LQ < 85'],
                    ],
                ],
            ],

            'class_overview' => [
                'label' => 'Klassenergebnis (Übersicht)',
                'description' => 'Ergebnisübersicht einer Lerngruppe für eine Erhebung.',
                'variables' => [
                    'school_name' => 'Name der Schule',
                    'group_name' => 'Lerngruppe',
                    'run_name' => 'Erhebung',
                    'date' => 'Erstellungsdatum',
                    'stats' => 'Aggregate: avg, median, min, max, count',
                    'rows' => 'Schüler-Liste mit Code/LQ/Rohwert',
                ],
                'sample' => [
                    'school_name' => 'Beispielschule',
                    'group_name' => '6a',
                    'run_name' => 'Eingangstest 6a',
                    'date' => '30.04.2026',
                    'stats' => ['avg' => 96.4, 'median' => 98, 'min' => 68, 'max' => 124, 'count' => 22],
                    'rows' => [
                        ['code' => 'BSP-00042', 'score_raw' => 64, 'lq' => 102],
                        ['code' => 'BSP-00043', 'score_raw' => 70, 'lq' => 110],
                    ],
                ],
            ],

            'credentials' => [
                'label' => 'Benutzer-Zugangsdaten',
                'description' => 'Druckbare Zugangsdaten für neue Benutzer.',
                'variables' => [
                    'school_name' => 'Name der Schule',
                    'username' => 'Benutzername',
                    'display_name' => 'Anzeigename',
                    'initial_password' => 'Initial-Passwort (nur einmalig nach Anlage)',
                    'login_url' => 'URL zum Login',
                    'date' => 'Erstellungsdatum',
                ],
                'sample' => [
                    'school_name' => 'Beispielschule',
                    'username' => 'm.musterlehrer',
                    'display_name' => 'Maria Musterlehrer',
                    'initial_password' => 'XKt7-pQ3z-9MNa',
                    'login_url' => 'https://lsp.schule.de/admin',
                    'date' => '30.04.2026',
                ],
            ],
        ];
    }

    public static function for(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    public static function options(): array
    {
        $out = [];
        foreach (self::types() as $key => $meta) {
            $out[$key] = $meta['label'];
        }

        return $out;
    }
}
