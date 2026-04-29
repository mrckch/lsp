<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearningGroupResource\Pages;

use App\Filament\Resources\LearningGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLearningGroup extends CreateRecord
{
    protected static string $resource = LearningGroupResource::class;
}
