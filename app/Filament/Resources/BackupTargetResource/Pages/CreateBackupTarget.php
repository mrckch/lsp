<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackupTargetResource\Pages;

use App\Filament\Resources\BackupTargetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBackupTarget extends CreateRecord
{
    protected static string $resource = BackupTargetResource::class;
}
