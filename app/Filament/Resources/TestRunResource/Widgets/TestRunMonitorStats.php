<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Widgets;

use App\Domain\TestRun\TestRunProgress;
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
        if ($this->record === null || auth()->user() === null) {
            return [];
        }

        $p = app(TestRunProgress::class)->summarize($this->record, auth()->user());
        $total = $p['total'];

        return [
            Stat::make('Schüler/innen', (string) $total)
                ->description($p['not_started'].' noch nicht angemeldet')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray'),
            Stat::make('Läuft gerade', (string) $p['running'])
                ->descriptionIcon('heroicon-m-play-circle')
                ->description('angemeldet, noch nicht abgegeben')
                ->color($p['running'] > 0 ? 'info' : 'gray'),
            Stat::make('Fertig', $p['finished'].' / '.$total)
                ->description($total > 0 ? round($p['finished'] / $total * 100).' %' : '–')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($total > 0 && $p['finished'] === $total ? 'success' : 'primary'),
            Stat::make('Ø LQ', $p['avg_lq'] !== null ? (string) $p['avg_lq'] : '–')
                ->description('Ø Rohwert '.($p['avg_score'] !== null ? number_format($p['avg_score'], 1, ',', '') : '–'))
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($p['avg_lq'] === null ? 'gray' : ($p['avg_lq'] < 85 ? 'warning' : 'success')),
        ];
    }
}
