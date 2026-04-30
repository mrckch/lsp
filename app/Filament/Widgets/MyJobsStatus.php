<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\PrintJob\Models\GeneratedDocument;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Zeigt die letzten Aktivitäten des aktuell eingeloggten Users:
 *  - vom User angestoßene erzeugte Dokumente (PDF/ZIP)
 *  - vom User versendete Mails
 *
 * Polling alle 10s, damit fertige Bulk-Jobs zeitnah erscheinen.
 */
class MyJobsStatus extends Widget
{
    protected static ?int $sort = 80;

    protected static string $view = 'filament.widgets.my-jobs-status';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '10s';

    public static function canView(): bool
    {
        return auth()->check();
    }

    /** @return Collection<int, array<string,mixed>> */
    public function getActivities(int $limit = 10): Collection
    {
        $userId = auth()->id();
        if ($userId === null) {
            return collect();
        }

        $docs = GeneratedDocument::query()
            ->where('created_by_user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (GeneratedDocument $d) => [
                'when' => $d->created_at,
                'kind' => 'document',
                'title' => $d->file_name,
                'subtitle' => $this->formatBytes((int) $d->size_bytes).' · '.$d->mime_type,
                'status' => 'fertig',
                'status_color' => '#16a34a',
                'includes_clearnames' => (bool) $d->includes_clearnames,
                'url' => route('filament.admin.resources.generated-documents.index'),
            ]);

        $mails = MailMessage::query()
            ->where('sent_by_user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (MailMessage $m) => [
                'when' => $m->created_at,
                'kind' => 'mail',
                'title' => $m->subject,
                'subtitle' => $this->stringifyTo($m->to_addresses),
                'status' => $m->status,
                'status_color' => match ($m->status) {
                    'sent' => '#16a34a',
                    'queued' => '#2563eb',
                    'failed' => '#dc2626',
                    'bounced' => '#ea580c',
                    default => '#6b7280',
                },
                'includes_clearnames' => (bool) $m->includes_clearnames,
                'url' => null,
            ]);

        $failures = AuditLog::query()
            ->where('action', 'job.failed')
            ->where('actor_user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $a) => [
                'when' => $a->created_at,
                'kind' => 'failure',
                'title' => $this->failureTitle($a->context['kind'] ?? null),
                'subtitle' => $a->context['error'] ?? '',
                'status' => 'fehlgeschlagen',
                'status_color' => '#dc2626',
                'includes_clearnames' => false,
                'url' => null,
            ]);

        return $docs->concat($mails)->concat($failures)
            ->sortByDesc(fn ($r) => $r['when'] ?? Carbon::createFromTimestamp(0))
            ->values()
            ->take($limit);
    }

    private function failureTitle(?string $kind): string
    {
        return match ($kind) {
            'bulk_feedback_zip' => 'Bulk-Rückmeldungs-ZIP',
            'bulk_feedback_mail' => 'Bulk-Rückmeldungs-Mail',
            'bulk_history_zip' => 'Bulk-Verlaufs-ZIP',
            default => 'Hintergrund-Job',
        };
    }

    private function stringifyTo(?string $json): string
    {
        if ($json === null || $json === '') {
            return '–';
        }
        $arr = json_decode($json, true);
        if (! is_array($arr) || empty($arr)) {
            return '–';
        }

        return implode(', ', array_slice($arr, 0, 3))
            .(count($arr) > 3 ? ' +'.(count($arr) - 3) : '');
    }

    private function formatBytes(int $b): string
    {
        if ($b < 1024) {
            return $b.' B';
        }
        if ($b < 1024 * 1024) {
            return round($b / 1024, 1).' KB';
        }

        return round($b / 1024 / 1024, 1).' MB';
    }
}
