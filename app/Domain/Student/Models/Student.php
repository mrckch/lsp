<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Crypto\Casts\EncryptedName;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'external_student_id',
        'external_id_source',
        'student_code',
        'first_name_encrypted',
        'last_name_encrypted',
        'gender',
        'status',
        'archived_at',
        'archived_reason',
    ];

    protected function casts(): array
    {
        return [
            'first_name_encrypted' => EncryptedName::class,
            'last_name_encrypted' => EncryptedName::class,
            'archived_at' => 'datetime',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StudentGroupMembership::class);
    }

    public function learningGroups(): BelongsToMany
    {
        return $this->belongsToMany(LearningGroup::class, 'student_group_memberships')
            ->withTimestamps();
    }

    public function schoolYears(): BelongsToMany
    {
        return $this->belongsToMany(SchoolYear::class, 'student_enrollments')
            ->withPivot(['grade_level', 'is_repeater'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'aktiv');
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->where('status', 'archiviert');
    }

    public function archive(?string $reason = null): void
    {
        $this->update([
            'status' => 'archiviert',
            'archived_at' => now(),
            'archived_reason' => $reason,
        ]);
    }

    public function unarchive(): void
    {
        $this->update([
            'status' => 'aktiv',
            'archived_at' => null,
            'archived_reason' => null,
        ]);
    }

    /**
     * Generiert einen eindeutigen Schülercode mit Schul-Kürzel.
     */
    public static function generateUniqueCode(string $shortName = 'LSP'): string
    {
        $count = self::query()->withTrashed()->count() + 1;
        do {
            $code = strtoupper($shortName).'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            $count++;
        } while (self::query()->withTrashed()->where('student_code', $code)->exists());

        return $code;
    }
}
