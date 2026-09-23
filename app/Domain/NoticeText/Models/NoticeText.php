<?php

declare(strict_types=1);

namespace App\Domain\NoticeText\Models;

use Illuminate\Database\Eloquent\Model;

class NoticeText extends Model
{
    protected $fillable = ['name', 'content', 'is_default', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Es gibt höchstens einen Standard-Hinweistext
        static::saved(function (NoticeText $text): void {
            if ($text->is_default) {
                static::query()->whereKeyNot($text->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    /** Aktiver Standard-Hinweistext (Vorauswahl für neue Testdurchläufe, Fallback im Schüler-Test). */
    public static function defaultText(): ?self
    {
        return static::query()->where('is_default', true)->where('status', 'aktiv')->first();
    }
}
