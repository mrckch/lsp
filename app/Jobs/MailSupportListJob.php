<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Mail\MailService;
use App\Domain\Permission\ScopeFilter;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\SupportThreshold\ThresholdEvaluator;
use App\Jobs\Concerns\LogsFailureToAudit;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Erzeugt asynchron ein PDF der Förderbedarfsliste mit den gleichen Filtern wie
 * in der UI und versendet es per Mail an einen Empfänger
 * (z. B. Förderkoordination, Schulleitung).
 */
class MailSupportListJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LogsFailureToAudit;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    /**
     * @param  array{school_year_id?:int, severity?:string, grade_level?:string}  $filters
     */
    public function __construct(
        public readonly array $filters,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly ?int $userId,
    ) {
        $this->onQueue('mail');
    }

    protected function failureContext(): array
    {
        return ['recipient' => $this->recipient, 'kind' => 'mail_support_list'];
    }

    public function handle(MailService $mail, ThresholdEvaluator $evaluator): void
    {
        $user = $this->userId ? User::find($this->userId) : null;

        $rows = $this->buildRows($evaluator, $user);
        if (empty($rows)) {
            return;
        }

        $template = PrintTemplate::query()->where('key', 'foerderbedarfsliste')->first();
        if (! $template?->currentVersion) {
            throw new \RuntimeException("Druckvorlage 'foerderbedarfsliste' nicht vorhanden.");
        }
        $version = $template->currentVersion;

        $vars = [
            'school_name' => AppSetting::singleton()->school_name ?? '',
            'date' => now()->format('d.m.Y'),
            'rows' => $rows,
        ];

        $gotenberg = app(GotenbergClient::class);
        $runner = new PrintJobRunner($gotenberg);
        $html = $runner->renderTemplate($version->html_content, $vars);
        $pdf = $gotenberg->htmlToPdf($html, $version->css_content);

        $mail->sendWithRawAttachment(
            to: [$this->recipient],
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            attachmentName: 'foerderbedarf_'.now()->format('Ymd_His').'.pdf',
            attachmentMime: 'application/pdf',
            attachmentBytes: $pdf,
            includesClearnames: true,
            userId: $this->userId,
        );
    }

    /** @return list<array<string,mixed>> */
    private function buildRows(ThresholdEvaluator $evaluator, ?User $user): array
    {
        $hits = $evaluator->evaluateAll(! empty($this->filters['school_year_id'])
            ? (int) $this->filters['school_year_id']
            : null);

        $allowed = $user ? app(ScopeFilter::class)->scopesFor($user) : null;
        $sev = $this->filters['severity'] ?? 'all';
        $grade = $this->filters['grade_level'] ?? null;

        $rows = [];
        foreach ($hits as $hit) {
            $student = $hit['student'];
            $threshold = $hit['threshold'];
            $attempt = $hit['attempt'];

            if ($sev === 'foerderbedarf' && $threshold->severity !== 'foerderbedarf') {
                continue;
            }
            if ($sev === 'auffaellig' && ! in_array($threshold->severity, ['auffaellig', 'foerderbedarf'], true)) {
                continue;
            }

            $membership = $student->memberships()->with('learningGroup')->orderByDesc('id')->first();
            $group = $membership?->learningGroup;

            if ($allowed !== null && $group && ! in_array($group->id, $allowed, true)) {
                continue;
            }
            if ($grade !== null && $grade !== '' && $group && $group->grade_level !== $grade) {
                continue;
            }

            $rows[] = [
                'student' => $student->first_name_encrypted.' '.$student->last_name_encrypted,
                'student_code' => $student->student_code,
                'group' => $group?->name ?? '–',
                'lq' => $attempt->lq_current,
                'severity' => $threshold->severity,
                'threshold_name' => $threshold->name,
            ];
        }

        return $rows;
    }
}
