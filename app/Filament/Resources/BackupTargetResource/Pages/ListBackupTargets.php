<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackupTargetResource\Pages;

use App\Filament\Resources\BackupTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBackupTargets extends ListRecords
{
    protected static string $resource = BackupTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
