<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoticeTextResource\Pages;

use App\Filament\Resources\NoticeTextResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNoticeText extends CreateRecord
{
    protected static string $resource = NoticeTextResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
