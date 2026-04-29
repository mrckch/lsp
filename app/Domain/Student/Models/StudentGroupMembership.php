<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGroupMembership extends Model
{
    protected $fillable = [
        'student_id',
        'learning_group_id',
        'school_year_id',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function learningGroup(): BelongsTo
    {
        return $this->belongsTo(LearningGroup::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }
}
