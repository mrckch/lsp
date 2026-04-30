<?php

declare(strict_types=1);

namespace App\Filament\Resources\NormTableResource\Pages;

use App\Filament\Resources\NormTableResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNormTable extends EditRecord
{
    protected static string $resource = NormTableResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
