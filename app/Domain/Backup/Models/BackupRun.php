<?php

declare(strict_types=1);

namespace App\Domain\Backup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'backup_target_id', 'trigger', 'status',
        'started_at', 'finished_at',
        'file_name', 'size_bytes', 'sha256',
        'includes_db', 'includes_files', 'includes_config',
        'error_message', 'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'includes_db' => 'boolean',
            'includes_files' => 'boolean',
            'includes_config' => 'boolean',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'backup_target_id');
    }
}
