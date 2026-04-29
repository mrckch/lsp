<?php

declare(strict_types=1);

namespace App\Domain\NormTable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormTableRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'norm_table_id',
        'raw_score',
        'quotient_female',
        'quotient_male',
        'quotient_diverse',
    ];

    public function normTable(): BelongsTo
    {
        return $this->belongsTo(NormTable::class);
    }
}
