<?php

declare(strict_types=1);

namespace App\Domain\Attempt\Models;

use App\Domain\NormTable\Models\NormTable;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestAttempt extends Model
{
    protected $fillable = [
        'student_id',
        'test_run_id',
        'questionnaire_id',
        'parallel_form',
        'norm_table_id',
        'status',
        'started_at',
        'main_started_at',
        'submitted_at',
        'time_limit_seconds',
        'score_raw',
        'lq_at_submission',
        'lq_current',
        'lq_calculated_at',
        'ended_by',
        'reset_by_user_id',
        'reset_reason',
        'login_code_used',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'main_started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'lq_calculated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }

    public function normTable(): BelongsTo
    {
        return $this->belongsTo(NormTable::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }

    public function lqHistory(): HasMany
    {
        return $this->hasMany(AttemptLqHistory::class);
    }

    public function resetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by_user_id');
    }

    /**
     * Restzeit des Haupttests in Sekunden. Vor „Test starten“ die volle Zeit.
     */
    public function remainingSeconds(): int
    {
        if ($this->main_started_at === null) {
            return (int) $this->time_limit_seconds;
        }

        $elapsed = (int) $this->main_started_at->diffInSeconds(now(), true);

        return max(0, (int) $this->time_limit_seconds - $elapsed);
    }
}
