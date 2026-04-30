<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportThresholdResource\Pages;

use App\Filament\Resources\SupportThresholdResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupportThresholds extends ListRecords
{
    protected static string $resource = SupportThresholdResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
