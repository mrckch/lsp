<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Domain\TestRun\Models\TestRun;
use App\Filament\Resources\TestRunResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTestRun extends CreateRecord
{
    protected static string $resource = TestRunResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();
        $data['owner_user_id'] = auth()->id();
        $data['short_code'] = TestRun::generateShortCode();

        return $data;
    }
}
