<?php

declare(strict_types=1);

namespace App\Domain\Attempt\Models;

use App\Domain\NormTable\Models\NormTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptLqHistory extends Model
{
    public $timestamps = false;

    protected $table = 'attempt_lq_history';

    protected $fillable = [
        'test_attempt_id',
        'norm_table_id',
        'lq_value',
        'reason',
        'calculated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['calculated_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'test_attempt_id');
    }

    public function normTable(): BelongsTo
    {
        return $this->belongsTo(NormTable::class);
    }
}
