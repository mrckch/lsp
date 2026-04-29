<?php

declare(strict_types=1);

namespace App\Domain\TestRun\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestRunSecurity extends Model
{
    protected $table = 'test_run_security';

    protected $fillable = [
        'test_run_id',
        'teacher_access_code',
        'teacher_access_code_is_active',
        'clearname_release_code_hash',
        'clearname_code_generated_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'teacher_access_code_is_active' => 'boolean',
            'clearname_code_generated_at' => 'datetime',
        ];
    }

    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }
}
