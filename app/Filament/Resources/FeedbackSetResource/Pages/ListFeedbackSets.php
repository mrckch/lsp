<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeedbackSetResource\Pages;

use App\Filament\Resources\FeedbackSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeedbackSets extends ListRecords
{
    protected static string $resource = FeedbackSetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
