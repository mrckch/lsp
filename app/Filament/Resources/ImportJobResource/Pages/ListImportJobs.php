<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportJobResource\Pages;

use App\Filament\Resources\ImportJobResource;
use Filament\Resources\Pages\ListRecords;

class ListImportJobs extends ListRecords
{
    protected static string $resource = ImportJobResource::class;
}
