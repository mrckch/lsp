<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gespeicherte Auswertung der Datenanalyse: Filter + Ansicht (settings).
 * Freigegebene Presets sehen alle Nutzer der Seite – die Daten bleiben
 * trotzdem auf deren eigene Sichtbarkeit beschränkt.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $settings
 * @property bool $is_shared
 */
class AnalysisPreset extends Model
{
    protected $fillable = ['user_id', 'name', 'settings', 'is_shared'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Eigene + freigegebene Presets */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('user_id', $user->id)->orWhere('is_shared', true));
    }
}
