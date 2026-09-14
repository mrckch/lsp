<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuestionnaireResource\Pages;

use App\Filament\Resources\QuestionnaireResource;
use App\Filament\Resources\QuestionnaireResource\Actions\ImportQuestionsAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuestionnaires extends ListRecords
{
    protected static string $resource = QuestionnaireResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportQuestionsAction::templates(),
            ImportQuestionsAction::forNewQuestionnaire(),
            CreateAction::make(),
        ];
    }
}
