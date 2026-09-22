<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use Illuminate\Database\Seeder;

/**
 * Basis-Druckvorlagen als Bestand — je eine pro bekanntem Template-Typ
 * (siehe TemplateCatalog), auch bei Neuinstallation vorhanden.
 *
 * Idempotent: existiert eine Vorlage (Key) mit Version bereits, bleibt sie
 * unangetastet. Alles kann wie gewohnt bearbeitet/versioniert/neu angelegt
 * werden. Die Vorlagen brauchen keinen angemeldeten Nutzer (System-Bestand,
 * created_by_user_id = null), laufen also auch beim Erst-Seed vor dem Setup.
 */
class DefaultPrintTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $row) {
            $tpl = PrintTemplate::query()->firstOrCreate(
                ['key' => $row['key']],
                ['name' => $row['name'], 'type' => $row['type'], 'is_system' => true],
            );

            if ($tpl->versions()->exists()) {
                continue;
            }

            $version = PrintTemplateVersion::query()->create([
                'print_template_id' => $tpl->id,
                'version_number' => 1,
                'html_content' => $row['html'],
                'css_content' => $this->commonCss(),
                'created_by_user_id' => null,
            ]);
            $tpl->update(['current_version_id' => $version->id]);
        }
    }

    /**
     * @return list<array{key:string, name:string, type:string, html:string}>
     */
    private function defaults(): array
    {
        return [
            [
                'key' => 'rueckmeldung',
                'name' => 'Rückmeldebogen (pro Schüler)',
                'type' => 'student_feedback',
                'html' => <<<'HTML'
                    <h1>Lese-Screening – Rückmeldung</h1>
                    <p class="muted">Schule: {{school_name}} · Erstellt am {{date}}</p>
                    <div class="box">
                        <strong>{{student_name}}</strong> · Klasse {{group_name}} · {{school_year}}<br>
                        <span class="muted">{{test_run_name}} ({{assessment_type}})</span>
                    </div>
                    <h2>Ergebnis</h2>
                    <p>Rohwert (richtige Antworten): <strong>{{score_raw}}</strong></p>
                    <p>Lesequotient (LQ): <span class="lq">{{lq}}</span></p>
                    <h2>Einordnung</h2>
                    <p>{{feedback_text}}</p>
                    HTML,
            ],
            [
                'key' => 'qr_liste',
                'name' => 'Zugangsdaten-Liste (Erhebung)',
                'type' => 'login_codes',
                'html' => <<<'HTML'
                    <h1>Zugangsdaten – {{run_name}}</h1>
                    <p class="muted">Schule: {{school_name}} · Klasse {{group_name}} · {{date}}</p>
                    <p>Bitte die Zugangscodes an die Schülerinnen und Schüler austeilen. Jeder Code gilt nur für diese Erhebung.</p>
                    {{students}}
                    HTML,
            ],
            [
                'key' => 'verlaufsdiagramm',
                'name' => 'Lese-Verlauf (Längsschnitt)',
                'type' => 'student_history',
                'html' => <<<'HTML'
                    <h1>Lese-Verlauf – {{student_name}}</h1>
                    <p class="muted">Schule: {{school_name}} · Code {{student_code}} · {{date}}</p>
                    <p>Entwicklung des Lesequotienten (LQ) über die bisherigen Erhebungen.</p>
                    {{history}}
                    HTML,
            ],
            [
                'key' => 'foerderbedarfsliste',
                'name' => 'Förderbedarfs-Liste',
                'type' => 'support_list',
                'html' => <<<'HTML'
                    <h1>Förderbedarfs-Liste</h1>
                    <p class="muted">Schule: {{school_name}} · Erstellt am {{date}}</p>
                    <p>Schülerinnen und Schüler, die mindestens eine konfigurierte Förderbedarfs-Schwelle erreichen.</p>
                    {{rows}}
                    HTML,
            ],
            [
                'key' => 'klassenergebnis',
                'name' => 'Klassenergebnis (Übersicht)',
                'type' => 'class_overview',
                'html' => <<<'HTML'
                    <h1>Klassenergebnis – {{group_name}}</h1>
                    <p class="muted">Schule: {{school_name}} · {{run_name}} · {{date}}</p>
                    <h2>Kennzahlen</h2>
                    {{stats}}
                    <h2>Einzelergebnisse</h2>
                    {{rows}}
                    HTML,
            ],
            [
                'key' => 'benutzer_zugangsdaten',
                'name' => 'Benutzer-Zugangsdaten',
                'type' => 'credentials',
                'html' => <<<'HTML'
                    <h1>Zugangsdaten – Lese-Screening-Portal</h1>
                    <p class="muted">Schule: {{school_name}} · Erstellt am {{date}}</p>
                    <div class="box">
                        <p><strong>Anzeigename:</strong> {{display_name}}</p>
                        <p><strong>Benutzername:</strong> {{username}}</p>
                        <p><strong>Initial-Passwort:</strong> <span class="lq" style="font-size:16pt">{{initial_password}}</span></p>
                        <p><strong>Login:</strong> {{login_url}}</p>
                    </div>
                    <p class="muted">Bitte das Initial-Passwort beim ersten Login ändern und diese Zugangsdaten vertraulich behandeln.</p>
                    HTML,
            ],
        ];
    }

    private function commonCss(): string
    {
        return <<<'CSS'
            @page { size: A4; margin: 2cm; }
            body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #111827; }
            h1 { font-size: 16pt; color: #1e3a8a; margin: 0 0 0.4cm; }
            h2 { font-size: 13pt; color: #374151; margin: 0.6cm 0 0.2cm; }
            p { margin: 0.15cm 0; }
            .muted { color: #6b7280; font-size: 9pt; }
            .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 0.4cm; margin: 0.3cm 0; }
            .lq { font-size: 24pt; font-weight: bold; color: #1e3a8a; }
            table.tpl-table { width: 100%; border-collapse: collapse; margin: 0.3cm 0; }
            table.tpl-table th, table.tpl-table td { padding: 0.15cm 0.35cm; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 10pt; }
            table.tpl-table thead th { background: #f3f4f6; color: #374151; }
            table.tpl-kv th { width: 30%; color: #374151; }
            ul.tpl-list { margin: 0.2cm 0; padding-left: 0.6cm; }
            CSS;
    }
}
