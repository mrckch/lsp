<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentTypeResource\Pages;

use App\Filament\Resources\AssessmentTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAssessmentType extends CreateRecord
{
    protected static string $resource = AssessmentTypeResource::class;
}
