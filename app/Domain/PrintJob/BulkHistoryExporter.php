<?php

declare(strict_types=1);

namespace App\Domain\PrintJob;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Permission\ScopeFilter;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\Student\Models\Student;
use App\Models\AppSetting;
use App\Models\User;

/**
 * Erzeugt für eine Liste von Schülern jeweils ein Verlaufs-PDF (Längsschnitt)
 * und packt sie in ein ZIP. Schüler ohne Versuche werden mit Hinweis übersprungen.
 */
final class BulkHistoryExporter
{
    public function __construct(
        private readonly GotenbergClient $gotenberg,
        private readonly AnalyticsService $analytics,
    ) {}

    /**
     * @param  array<int>  $studentIds
     * @param  User|null  $forUser  wenn gesetzt, Scope-Filter applizieren
     * @return array{zip:string, count:int, skipped:int, errors:list<string>}
     */
    public function exportFor(array $studentIds, ?User $forUser = null, string $templateKey = 'verlaufsdiagramm'): array
    {
        $template = PrintTemplate::query()->where('key', $templateKey)->first();
        if (! $template?->currentVersion) {
            throw new \RuntimeException("Druckvorlage '$templateKey' nicht vorhanden.");
        }
        $version = $template->currentVersion;

        $studentsQ = Student::query()->whereIn('id', $studentIds);
        if ($forUser !== null) {
            $studentsQ = app(ScopeFilter::class)->applyToStudents($studentsQ, $forUser);
        }
        $students = $studentsQ->get();

        $runner = new PrintJobRunner($this->gotenberg);
        $errors = [];
        $files = [];
        $skipped = 0;

        $schoolName = AppSetting::singleton()->school_name ?? '';

        foreach ($students as $student) {
            try {
                $history = $this->analytics->studentHistory($student);
                if ($history->isEmpty()) {
                    $skipped++;

                    continue;
                }

                $vars = [
                    'school_name' => $schoolName,
                    'student_name' => $student->first_name_encrypted.' '.$student->last_name_encrypted,
                    'student_code' => $student->student_code,
                    'date' => now()->format('d.m.Y'),
                    'history' => $history->map(fn ($r) => [
                        'date' => optional($r['submitted_at'])->format('d.m.Y'),
                        'label' => $r['test_run_name'],
                        'lq' => $r['lq_current'],
                    ])->all(),
                ];
                $html = $runner->renderTemplate($version->html_content, $vars);
                $pdf = $this->gotenberg->htmlToPdf($html, $version->css_content);
                $files['verlauf_'.$student->student_code.'.pdf'] = $pdf;
            } catch (\Throwable $e) {
                $errors[] = "Schüler {$student->student_code}: {$e->getMessage()}";
            }
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'lsp_history_').'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Konnte ZIP nicht öffnen: $zipPath");
        }
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return [
            'zip' => $zipPath,
            'count' => count($files),
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
