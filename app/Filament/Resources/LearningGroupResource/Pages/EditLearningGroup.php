<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearningGroupResource\Pages;

use App\Filament\Resources\LearningGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLearningGroup extends EditRecord
{
    protected static string $resource = LearningGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
