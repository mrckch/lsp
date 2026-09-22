<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Permission\ScopeFilter;
use App\Domain\TestRun\Models\TestRun;
use App\Domain\TestRun\TestRunProgress;
use App\Filament\Resources\TestRunResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Eine Kachel je aktivem Testdurchlauf mit Mini-Statistik; Klick öffnet die
 * Übersicht. Sichtbar für Admin und alle Lehrkräfte, deren Lerngruppen am Run
 * beteiligt sind (Scope); Zahlen nur für Schüler im eigenen Scope.
 */
class ActiveTestRunsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 50;

    protected static ?string $pollingInterval = '15s';

    protected static bool $isLazy = false;

    protected ?string $heading = 'Aktive Testdurchläufe';

    public static function canView(): bool
    {
        return auth()->user() !== null && TestRunResource::canViewAny() && self::activeRunsQuery()->exists();
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $progress = app(TestRunProgress::class);

        return self::activeRunsQuery()
            ->orderBy('name')
            ->get()
            ->map(function (TestRun $run) use ($progress) {
                $p = $progress->summarize($run, auth()->user());
                $total = $p['total'];
                $parts = [$p['running'].' laufen gerade'];
                if ($p['not_started'] > 0) {
                    $parts[] = $p['not_started'].' nicht angemeldet';
                }
                if ($p['avg_lq'] !== null) {
                    $parts[] = 'Ø LQ '.$p['avg_lq'];
                }

                return Stat::make($run->name, $p['finished'].' / '.$total.' fertig')
                    ->description(implode(' · ', $parts))
                    ->descriptionIcon($p['running'] > 0 ? 'heroicon-m-play-circle' : 'heroicon-m-check-circle')
                    ->color($total > 0 && $p['finished'] === $total ? 'success' : ($p['running'] > 0 ? 'info' : 'gray'))
                    ->url(TestRunResource::getUrl('monitor', ['record' => $run]));
            })
            ->all();
    }

    private static function activeRunsQuery()
    {
        return app(ScopeFilter::class)->applyToTestRuns(
            TestRun::query()->where('status', 'aktiv'),
            auth()->user(),
        );
    }
}
