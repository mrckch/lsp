<?php

declare(strict_types=1);

namespace App\Domain\TestRun\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentType extends Model
{
    protected $fillable = ['key', 'label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
