<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeedbackSetResource\Pages;

use App\Filament\Resources\FeedbackSetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeedbackSet extends CreateRecord
{
    protected static string $resource = FeedbackSetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
