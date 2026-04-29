<?php

declare(strict_types=1);

namespace App\Domain\PrintJob\Models;

use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'print_template_version_id',
        'context_type',
        'context_id',
        'parameters',
        'status',
        'error_message',
        'output_document_id',
        'requested_by_user_id',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(PrintTemplateVersion::class, 'print_template_version_id');
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'output_document_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
