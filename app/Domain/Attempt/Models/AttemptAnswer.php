<?php

declare(strict_types=1);

namespace App\Domain\Attempt\Models;

use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptAnswer extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'test_attempt_id',
        'question_id',
        'given_answer',
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'test_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireQuestion::class, 'question_id');
    }
}
