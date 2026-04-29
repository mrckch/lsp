<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\School\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_source_id',
        'school_year_id',
        'group_type',
        'filename',
        'status',
        'mapping',
        'stats',
        'started_by_user_id',
        'started_at',
        'validated_at',
        'committed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'validated_at' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    public function diffEntries(): HasMany
    {
        return $this->hasMany(ImportDiffEntry::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
