<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackupTargetResource\Pages;

use App\Domain\Backup\Models\BackupTarget;
use App\Filament\Resources\BackupTargetResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBackupTarget extends CreateRecord
{
    protected static string $resource = BackupTargetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // encryption_password ist ein Accessor/Mutator und nicht fillable —
        // bei Mass-Assignment würde es stillschweigend verworfen.
        $password = $data['encryption_password'] ?? null;
        unset($data['encryption_password']);

        $target = new BackupTarget($data);
        $target->encryption_password = $password;
        $target->save();

        return $target;
    }
}
