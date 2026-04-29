<?php

declare(strict_types=1);

namespace App\Domain\PrintJob\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedDocument extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'file_name',
        'file_path',
        'mime_type',
        'size_bytes',
        'includes_clearnames',
        'sha256',
        'expires_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'includes_clearnames' => 'boolean',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
