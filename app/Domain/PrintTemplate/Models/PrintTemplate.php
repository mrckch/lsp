<?php

declare(strict_types=1);

namespace App\Domain\PrintTemplate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'type', 'is_system', 'current_version_id',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PrintTemplateVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PrintTemplateVersion::class, 'current_version_id');
    }
}
