<?php

declare(strict_types=1);

namespace App\Domain\Questionnaire\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionnaireQuestion extends Model
{
    protected $fillable = [
        'questionnaire_id',
        'sort_order',
        'question_text',
        'correct_answer',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }
}
