<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Filament\Resources\TestRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTestRun extends EditRecord
{
    protected static string $resource = TestRunResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
