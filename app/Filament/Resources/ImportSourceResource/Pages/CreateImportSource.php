<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportSourceResource\Pages;

use App\Filament\Resources\ImportSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateImportSource extends CreateRecord
{
    protected static string $resource = ImportSourceResource::class;
}
