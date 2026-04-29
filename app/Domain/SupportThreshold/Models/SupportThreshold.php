<?php

declare(strict_types=1);

namespace App\Domain\SupportThreshold\Models;

use Illuminate\Database\Eloquent\Model;

class SupportThreshold extends Model
{
    protected $fillable = [
        'name', 'description', 'metric', 'operator', 'value',
        'window_count', 'severity', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
