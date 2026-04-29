<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearningGroupResource\Pages;

use App\Filament\Resources\LearningGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLearningGroups extends ListRecords
{
    protected static string $resource = LearningGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
