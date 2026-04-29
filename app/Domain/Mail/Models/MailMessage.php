<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'to_addresses', 'cc', 'bcc', 'subject', 'body_html', 'body_text',
        'status', 'error_message', 'related_entity_type', 'related_entity_id',
        'includes_clearnames', 'sent_by_user_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'includes_clearnames' => 'boolean',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
