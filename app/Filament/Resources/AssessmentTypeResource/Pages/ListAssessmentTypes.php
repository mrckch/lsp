<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentTypeResource\Pages;

use App\Filament\Resources\AssessmentTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssessmentTypes extends ListRecords
{
    protected static string $resource = AssessmentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
