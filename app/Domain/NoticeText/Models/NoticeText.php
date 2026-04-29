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
}
