<?php

declare(strict_types=1);

namespace App\Domain\FeedbackSet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackSetRange extends Model
{
    protected $fillable = [
        'feedback_set_id',
        'sort_order',
        'name',
        'match_type',
        'min_value',
        'max_value',
        'template_html',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function feedbackSet(): BelongsTo
    {
        return $this->belongsTo(FeedbackSet::class);
    }

    public function matches(int $value): bool
    {
        return $value >= $this->min_value && $value <= $this->max_value;
    }
}
