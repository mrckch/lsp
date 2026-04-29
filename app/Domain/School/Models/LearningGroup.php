<?php

declare(strict_types=1);

namespace App\Domain\School\Models;

use App\Domain\Student\Models\Student;
use App\Domain\Student\Models\StudentGroupMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningGroup extends Model
{
    protected $fillable = [
        'school_year_id',
        'name',
        'description',
        'group_type',
        'grade_level',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StudentGroupMembership::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_group_memberships')
            ->withTimestamps();
    }
}
