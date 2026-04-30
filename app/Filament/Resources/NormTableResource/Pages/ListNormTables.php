<?php

declare(strict_types=1);

namespace App\Filament\Resources\NormTableResource\Pages;

use App\Filament\Resources\NormTableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNormTables extends ListRecords
{
    protected static string $resource = NormTableResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
