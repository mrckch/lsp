<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoticeTextResource\Pages;

use App\Filament\Resources\NoticeTextResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNoticeText extends EditRecord
{
    protected static string $resource = NoticeTextResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
