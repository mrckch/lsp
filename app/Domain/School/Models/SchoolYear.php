<?php

declare(strict_types=1);

namespace App\Domain\School\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    protected $fillable = [
        'label',
        'start_date',
        'end_date',
        'is_active',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    public function learningGroups(): HasMany
    {
        return $this->hasMany(LearningGroup::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(\App\Domain\Student\Models\StudentEnrollment::class);
    }

    public static function active()
    {
        return self::query()->where('is_active', true)->where('is_archived', false);
    }
}
