<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\School\Models\SchoolYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentEnrollment extends Model
{
    protected $fillable = [
        'student_id',
        'school_year_id',
        'grade_level',
        'is_repeater',
        'enrolled_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
            'ended_at' => 'date',
            'is_repeater' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }
}
