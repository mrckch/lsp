<?php

declare(strict_types=1);

namespace App\Domain\PrintJob;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\FeedbackSet\Models\FeedbackSetRange;
use App\Domain\Permission\ScopeFilter;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\TestRun\Models\TestRun;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Erzeugt Rückmeldungs-PDFs für alle abgegebenen Versuche eines Test-Runs
 * (oder eine Teilmenge), bündelt die Ergebnisse als ZIP.
 */
final class BulkFeedbackGenerator
{
    public function __construct(private readonly GotenbergClient $gotenberg) {}

    /**
     * @param  array<int>|null  $attemptIds  null = alle abgegebenen
     * @param  User|null  $forUser  wenn gesetzt, nur Versuche von SuS in den
     *                              Lerngruppen-Scopes dieses Users (sonst alle)
     * @return array{zip:string, count:int, errors:list<string>}
     */
    public function generateForRun(
        TestRun $run,
        string $templateKey = 'rueckmeldung',
        ?array $attemptIds = null,
        ?User $forUser = null,
    ): array {
        $template = PrintTemplate::query()->where('key', $templateKey)->first();
        if (! $template?->currentVersion) {
            throw new \RuntimeException("Druckvorlage '$templateKey' ist nicht vorhanden oder hat keine Version.");
        }
        $version = $template->currentVersion;

        $attemptsQ = TestAttempt::query()
            ->with(['student', 'testRun.feedbackSet'])
            ->where('test_run_id', $run->id)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen']);
        if ($attemptIds !== null) {
            $attemptsQ->whereIn('id', $attemptIds);
        }
        if ($forUser !== null) {
            $attemptsQ = app(ScopeFilter::class)->applyToAttempts($attemptsQ, $forUser);
        }
        $attempts = $attemptsQ->get();

        $runner = new PrintJobRunner($this->gotenberg);
        $errors = [];
        $files = [];

        $feedbackRanges = $run->feedbackSet
            ? $run->feedbackSet->ranges()->where('is_active', true)->orderBy('sort_order')->get()
            : collect();

        $schoolName = AppSetting::singleton()->school_name ?? '';

        foreach ($attempts as $attempt) {
            try {
                $vars = $this->buildVarsForAttempt($attempt, $feedbackRanges, $schoolName);
                $html = $runner->renderTemplate($version->html_content, $vars);
                $pdf = $this->gotenberg->htmlToPdf($html, $version->css_content);
                $name = sprintf('rueckmeldung_%s.pdf', $attempt->student->student_code);
                $files[$name] = $pdf;
            } catch (\Throwable $e) {
                $errors[] = "Schüler {$attempt->student?->student_code}: {$e->getMessage()}";
            }
        }

        // ZIP bauen
        $zipPath = tempnam(sys_get_temp_dir(), 'lsp_bulk_').'.zip';
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
            'errors' => $errors,
        ];
    }

    private function buildVarsForAttempt(TestAttempt $attempt, Collection $ranges, string $schoolName): array
    {
        $student = $attempt->student;
        $lq = $attempt->lq_current;
        $rangeText = '';
        foreach ($ranges as $range) {
            /** @var FeedbackSetRange $range */
            $value = $range->match_type === 'lq' ? ($lq ?? -1) : $attempt->score_raw;
            if ($range->matches($value)) {
                $rangeText = $range->template_html;
                break;
            }
        }

        // Klassenname (erste verknüpfte Lerngruppe)
        $groupName = $student->learningGroups()
            ->orderByDesc('learning_groups.id')
            ->value('learning_groups.name') ?? '–';

        return [
            'school_name' => $schoolName,
            'date' => now()->format('d.m.Y'),
            'student_name' => $student->first_name_encrypted.' '.$student->last_name_encrypted,
            'student_code' => $student->student_code,
            'group_name' => $groupName,
            'school_year' => $attempt->testRun?->schoolYear?->label ?? '',
            'test_run_name' => $attempt->testRun?->name ?? '',
            'assessment_type' => $attempt->testRun?->assessmentType?->label ?? '',
            'score_raw' => $attempt->score_raw,
            'lq' => $lq ?? '–',
            'feedback_text' => $rangeText,
            '_includes_clearnames' => true,
        ];
    }
}
