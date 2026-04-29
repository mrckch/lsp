<?php

declare(strict_types=1);

namespace App\Domain\FeedbackSet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackSet extends Model
{
    protected $fillable = ['name', 'status', 'is_default', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function ranges(): HasMany
    {
        return $this->hasMany(FeedbackSetRange::class)->orderBy('sort_order');
    }
}
