<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Filament\Resources\TestRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTestRuns extends ListRecords
{
    protected static string $resource = TestRunResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
