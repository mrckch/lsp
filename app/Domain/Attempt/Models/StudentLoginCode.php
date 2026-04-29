<?php

declare(strict_types=1);

namespace App\Domain\Attempt\Models;

use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLoginCode extends Model
{
    protected $fillable = [
        'student_id',
        'test_run_id',
        'login_code',
        'status',
        'issued_at',
        'consumed_at',
        'reset_at',
        'reset_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'consumed_at' => 'datetime',
            'reset_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    public static function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::query()->where('login_code', $code)->exists());

        return $code;
    }
}
