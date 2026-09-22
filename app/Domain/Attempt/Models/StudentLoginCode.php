<?php

declare(strict_types=1);

namespace App\Domain\Attempt\Models;

use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use Illuminate\Database\Eloquent\Builder;
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

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TestRun, $this> */
    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    /**
     * Jüngster Versuch dieses Schülers in diesem Run. Nur verfügbar, wenn die
     * Query über {@see scopeWithAttemptInfo()} die Spalte `latest_attempt_id` lädt.
     *
     * @return BelongsTo<TestAttempt, $this>
     */
    public function latestAttempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'latest_attempt_id');
    }

    /**
     * Ergänzt `latest_attempt_id` und `attempts_total` (Versuche inkl. zurückgesetzter)
     * je Code – Grundlage der Übersicht eines Testdurchlaufs.
     */
    public function scopeWithAttemptInfo(Builder $query): Builder
    {
        $attempts = fn () => TestAttempt::query()
            ->whereColumn('test_attempts.student_id', 'student_login_codes.student_id')
            ->whereColumn('test_attempts.test_run_id', 'student_login_codes.test_run_id');

        return $query
            ->select('student_login_codes.*')
            ->addSelect([
                'latest_attempt_id' => $attempts()->select('test_attempts.id')->orderByDesc('test_attempts.id')->limit(1),
                'attempts_total' => $attempts()->selectRaw('count(*)'),
            ]);
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
