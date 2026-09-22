<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Widgets;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\ScopeFilter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/**
 * Kennzahlen eines Testdurchlaufs (Kopf der Übersichtsseite), im Scope des Users.
 */
class TestRunMonitorStats extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected static ?string $pollingInterval = '10s';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $user = auth()->user();
        $scope = app(ScopeFilter::class);

        $codes = $scope->applyToLoginCodes(
            StudentLoginCode::query()->where('test_run_id', $this->record->getKey()),
            $user,
        )->pluck('status');

        $attempts = $scope->applyToAttempts(
            TestAttempt::query()->where('test_run_id', $this->record->getKey()),
            $user,
        );
        $running = (clone $attempts)->where('status', 'gestartet')->count();
        $finished = (clone $attempts)->whereIn('status', ['abgegeben', 'zeit_abgelaufen']);
        $finishedCount = (clone $finished)->count();
        $avgScore = (clone $finished)->avg('score_raw');
        $avgLq = (clone $finished)->whereNotNull('lq_current')->avg('lq_current');

        $total = $codes->count();
        $notStarted = $codes->filter(fn ($s) => $s === 'aktiv')->count();

        return [
            Stat::make('Schüler/innen', (string) $total)
                ->description($notStarted.' noch nicht angemeldet')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray'),
            Stat::make('Läuft gerade', (string) $running)
                ->descriptionIcon('heroicon-m-play-circle')
                ->description('angemeldet, noch nicht abgegeben')
                ->color($running > 0 ? 'info' : 'gray'),
            Stat::make('Fertig', $finishedCount.' / '.$total)
                ->description($total > 0 ? round($finishedCount / $total * 100).' %' : '–')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($total > 0 && $finishedCount === $total ? 'success' : 'primary'),
            Stat::make('Ø LQ', $avgLq !== null ? (string) (int) round((float) $avgLq) : '–')
                ->description('Ø Rohwert '.($avgScore !== null ? number_format((float) $avgScore, 1, ',', '') : '–'))
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($avgLq === null ? 'gray' : ((float) $avgLq < 85 ? 'warning' : 'success')),
        ];
    }
}
