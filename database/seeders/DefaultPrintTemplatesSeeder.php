<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Models\User;
use Illuminate\Database\Seeder;

class DefaultPrintTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();
        if ($user === null) {
            return; // erst nach Setup
        }

        $defaults = [
            [
                'key' => 'rueckmeldung',
                'name' => 'Rückmeldebogen',
                'type' => 'student_feedback',
                'html' => $this->feedbackHtml(),
                'css' => $this->commonCss(),
            ],
            [
                'key' => 'qr_liste',
                'name' => 'QR-Code-Liste',
                'type' => 'login_codes',
                'html' => '<h1>Zugangscodes</h1><div>{{run_name}}</div>',
                'css' => $this->commonCss(),
            ],
            [
                'key' => 'verlaufsdiagramm',
                'name' => 'Verlaufsdiagramm',
                'type' => 'student_history',
                'html' => '<h1>Lese-Verlauf {{student_name}}</h1><div>{{history}}</div>',
                'css' => $this->commonCss(),
            ],
            [
                'key' => 'foerderbedarfsliste',
                'name' => 'Förderbedarfs-Liste',
                'type' => 'support_list',
                'html' => '<h1>Förderbedarf</h1><div>{{rows}}</div>',
                'css' => $this->commonCss(),
            ],
            [
                'key' => 'klassenergebnis',
                'name' => 'Klassenergebnis',
                'type' => 'class_overview',
                'html' => '<h1>Klassenergebnis {{group_name}}</h1><div>{{stats}}</div>',
                'css' => $this->commonCss(),
            ],
        ];

        foreach ($defaults as $row) {
            $tpl = PrintTemplate::query()->firstOrCreate(
                ['key' => $row['key']],
                ['name' => $row['name'], 'type' => $row['type'], 'is_system' => true],
            );

            if ($tpl->versions()->exists()) {
                continue;
            }

            $version = PrintTemplateVersion::create([
                'print_template_id' => $tpl->id,
                'version_number' => 1,
                'html_content' => $row['html'],
                'css_content' => $row['css'],
                'created_by_user_id' => $user->id,
            ]);
            $tpl->update(['current_version_id' => $version->id]);
        }
    }

    private function commonCss(): string
    {
        return <<<'CSS'
@page { size: A4; margin: 2cm; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #111827; }
h1 { font-size: 16pt; color: #1e3a8a; margin: 0 0 0.5cm; }
h2 { font-size: 13pt; color: #374151; margin-top: 0.5cm; }
.muted { color: #6b7280; font-size: 9pt; }
.box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 0.5cm; margin: 0.25cm 0; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 0.2cm 0.4cm; border-bottom: 1px solid #e5e7eb; text-align: left; }
.lq { font-size: 24pt; font-weight: bold; }
CSS;
    }

    private function feedbackHtml(): string
    {
        return <<<'HTML'
<h1>Lese-Screening – Rückmeldung</h1>
<p class="muted">Schule: {{school_name}} · Erstellt am {{date}}</p>
<div class="box">
    <strong>{{student_name}}</strong> · Klasse {{group_name}}
</div>
<h2>Ergebnis</h2>
<p>Rohwert: <strong>{{score_raw}}</strong></p>
<p>Lesequotient (LQ): <span class="lq">{{lq}}</span></p>
<h2>Einordnung</h2>
<p>{{feedback_text}}</p>
HTML;
    }
}
