<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportThresholdResource\Pages;

use App\Filament\Resources\SupportThresholdResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupportThreshold extends EditRecord
{
    protected static string $resource = SupportThresholdResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
