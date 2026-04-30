<?php

declare(strict_types=1);

namespace App\Filament\Resources\NormTableResource\Pages;

use App\Filament\Resources\NormTableResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNormTable extends CreateRecord
{
    protected static string $resource = NormTableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
