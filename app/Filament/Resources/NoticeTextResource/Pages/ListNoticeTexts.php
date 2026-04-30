<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoticeTextResource\Pages;

use App\Filament\Resources\NoticeTextResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNoticeTexts extends ListRecords
{
    protected static string $resource = NoticeTextResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
