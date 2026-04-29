<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'school_name',
        'school_short_name',
        'is_initialized',
        'initialized_at',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'is_initialized' => 'boolean',
            'initialized_at' => 'datetime',
            'options' => 'array',
        ];
    }

    public static function singleton(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['is_initialized' => false]);
    }

    public static function isInitialized(): bool
    {
        return self::query()->where('id', 1)->where('is_initialized', true)->exists();
    }
}
