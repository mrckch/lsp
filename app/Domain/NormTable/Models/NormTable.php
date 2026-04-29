<?php

declare(strict_types=1);

namespace App\Domain\NormTable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormTable extends Model
{
    protected $fillable = [
        'name',
        'version_label',
        'grade_level',
        'parallel_form',
        'source_type',
        'status',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(NormTableRow::class);
    }
}
