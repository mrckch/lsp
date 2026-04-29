<?php

declare(strict_types=1);

namespace App\Domain\Questionnaire\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionnairePracticeQuestion extends Model
{
    protected $fillable = ['questionnaire_id', 'sort_order', 'question_text', 'correct_answer'];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }
}
