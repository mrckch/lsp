<?php

declare(strict_types=1);

namespace App\Domain\PrintTemplate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintTemplateVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'print_template_id',
        'version_number',
        'html_content',
        'css_content',
        'variables_schema',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'variables_schema' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintTemplate::class, 'print_template_id');
    }
}
