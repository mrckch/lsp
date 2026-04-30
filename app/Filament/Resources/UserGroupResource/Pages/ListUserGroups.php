<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserGroupResource\Pages;

use App\Filament\Resources\UserGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserGroups extends ListRecords
{
    protected static string $resource = UserGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
