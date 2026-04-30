<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Permission\PermissionResolver;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tagesübersicht sicherheitsrelevanter Audit-Aktionen (heute / 7 Tage):
 *  - Klarnamen-Entsperrungen
 *  - Aktionen mit Klarnamen-Inhalten (Exports, Mails)
 *  - Lösch-/Archiv-Operationen
 *  - Job-Failures
 *
 * Sichtbar nur für User mit system.audit.view (Admin, Schulleitung).
 */
class AuditStats extends StatsOverviewWidget
{
    protected static ?int $sort = 70;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return app(PermissionResolver::class)->can($user, 'system.audit.view');
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $sevenDaysAgo = now()->subDays(7)->startOfDay();

        return [
            $this->stat(
                'Klarnamen entsperrt',
                ['clearname.unlock'],
                $today, $sevenDaysAgo,
                'heroicon-m-lock-open',
                'primary',
            ),
            $this->stat(
                'Aktionen mit Klarnamen',
                null,
                $today, $sevenDaysAgo,
                'heroicon-m-eye',
                'warning',
                onlyClearname: true,
            ),
            $this->stat(
                'Schüler gelöscht/archiviert',
                ['students.delete', 'students.archive'],
                $today, $sevenDaysAgo,
                'heroicon-m-trash',
                'danger',
            ),
            $this->stat(
                'Job-Failures',
                ['job.failed'],
                $today, $sevenDaysAgo,
                'heroicon-m-exclamation-triangle',
                'danger',
            ),
        ];
    }

    /**
     * @param  array<int,string>|null  $actions
     */
    private function stat(
        string $label,
        ?array $actions,
        \DateTimeInterface $today,
        \DateTimeInterface $weekStart,
        string $icon,
        string $color,
        bool $onlyClearname = false,
    ): Stat {
        $today = $this->countQuery($actions, $today, $onlyClearname);
        $week = $this->countQuery($actions, $weekStart, $onlyClearname);

        return Stat::make($label, (string) $today)
            ->description("$week in den letzten 7 Tagen")
            ->descriptionIcon($icon)
            ->color($color);
    }

    private function countQuery(?array $actions, \DateTimeInterface $since, bool $onlyClearname): int
    {
        $q = AuditLog::query()->where('created_at', '>=', $since);
        if ($actions !== null) {
            $q->whereIn('action', $actions);
        }
        if ($onlyClearname) {
            $q->where('includes_clearnames', true);
        }

        return (int) $q->count();
    }
}
