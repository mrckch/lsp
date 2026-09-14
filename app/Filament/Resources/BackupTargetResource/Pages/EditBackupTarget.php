<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackupTargetResource\Pages;

use App\Domain\Backup\Models\BackupTarget;
use App\Filament\Resources\BackupTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBackupTarget extends EditRecord
{
    protected static string $resource = BackupTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var BackupTarget $target */
        $target = $this->getRecord();
        $data = BackupTargetResource::keepUnchangedSecrets($data, $target);

        // Leeres Passwort = unverändert; sonst über den Mutator setzen (nicht fillable)
        if (filled($data['encryption_password'] ?? null)) {
            $target->encryption_password = $data['encryption_password'];
        }
        unset($data['encryption_password']);

        return $data;
    }
}
