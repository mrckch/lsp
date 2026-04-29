<?php

declare(strict_types=1);

namespace App\Domain\Questionnaire\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Questionnaire extends Model
{
    protected $fillable = [
        'name',
        'description',
        'parallel_form',
        'grade_level_target',
        'default_time_limit_seconds',
        'practice_time_seconds',
        'status',
        'created_by_user_id',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireQuestion::class)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function practiceQuestions(): HasMany
    {
        return $this->hasMany(QuestionnairePracticeQuestion::class)
            ->orderBy('sort_order');
    }
}
