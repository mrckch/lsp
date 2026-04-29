<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use App\Domain\PrintJob\Models\GeneratedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mail_message_id', 'generated_document_id',
        'file_name', 'mime_type', 'size_bytes',
    ];

    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }
}
