<?php

declare(strict_types=1);

namespace App\Domain\TestRun\Models;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\FeedbackSet\Models\FeedbackSet;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NoticeText\Models\NoticeText;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TestRun extends Model
{
    protected $fillable = [
        'school_year_id',
        'assessment_type_id',
        'name',
        'short_code',
        'status',
        'questionnaire_id',
        'norm_table_id',
        'feedback_set_id',
        'notice_text_id',
        'time_limit_seconds',
        'practice_time_seconds',
        'show_score_to_student',
        'allow_teacher_reset',
        'scheduled_for',
        'created_by_user_id',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'show_score_to_student' => 'boolean',
            'allow_teacher_reset' => 'boolean',
            'scheduled_for' => 'date',
        ];
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }

    public function normTable(): BelongsTo
    {
        return $this->belongsTo(NormTable::class);
    }

    public function feedbackSet(): BelongsTo
    {
        return $this->belongsTo(FeedbackSet::class);
    }

    public function noticeText(): BelongsTo
    {
        return $this->belongsTo(NoticeText::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function learningGroups(): BelongsToMany
    {
        return $this->belongsToMany(LearningGroup::class, 'test_run_groups');
    }

    public function security(): HasOne
    {
        return $this->hasOne(TestRunSecurity::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class);
    }

    public static function generateShortCode(): string
    {
        do {
            $code = 'R'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::query()->where('short_code', $code)->exists());

        return $code;
    }
}
