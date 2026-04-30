<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\PrintJob\GotenbergClient;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Zeigt im Dashboard, ob der Gotenberg-PDF-Service erreichbar ist.
 */
class PdfServiceHealth extends StatsOverviewWidget
{
    protected static ?int $sort = 90;

    protected function getStats(): array
    {
        $client = app(GotenbergClient::class);
        $ok = $client->ping();

        return [
            Stat::make('PDF-Service', $ok ? 'erreichbar' : 'nicht erreichbar')
                ->description($client->baseUrl())
                ->descriptionIcon($ok ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($ok ? 'success' : 'danger'),
        ];
    }
}
