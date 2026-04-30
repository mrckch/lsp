<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_type',
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'context',
        'includes_clearnames',
        'ip_address',
        'user_agent',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'includes_clearnames' => 'boolean',
            'created_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Nicht archivierte Einträge. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Nur archivierte Einträge. */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }
}
