<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportSourceResource\Pages;

use App\Filament\Resources\ImportSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportSources extends ListRecords
{
    protected static string $resource = ImportSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
