<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportDiffEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_job_id',
        'row_number',
        'external_student_id',
        'action',
        'matched_student_id',
        'payload',
        'errors',
        'admin_decision',
        'admin_decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'errors' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    public function matchedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'matched_student_id');
    }
}
