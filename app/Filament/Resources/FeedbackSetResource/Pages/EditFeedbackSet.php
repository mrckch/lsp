<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeedbackSetResource\Pages;

use App\Filament\Resources\FeedbackSetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeedbackSet extends EditRecord
{
    protected static string $resource = FeedbackSetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
