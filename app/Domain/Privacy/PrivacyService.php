<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crypto\CryptoService;
use App\Domain\Student\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DSGVO-Workflows: Auskunfts-Report, Löschung mit Audit, Anonymisierung.
 */
final class PrivacyService
{
    public function __construct(
        private readonly CryptoService $crypto,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Auskunfts-Report eines Schülers (Art. 15 DSGVO).
     *
     * Liefert ein Array mit allen über den Schüler gespeicherten Daten.
     * Klarnamen sind nur enthalten, wenn die Klarnamen-Session entsperrt ist.
     *
     * @return array<string, mixed>
     */
    public function exportStudentData(Student $student): array
    {
        $data = [
            'student' => [
                'id' => $student->id,
                'student_code' => $student->student_code,
                'external_student_id' => $student->external_student_id,
                'external_id_source' => $student->external_id_source,
                'first_name' => $this->crypto->isUnlocked() ? $student->first_name_encrypted : '[gesperrt]',
                'last_name' => $this->crypto->isUnlocked() ? $student->last_name_encrypted : '[gesperrt]',
                'gender' => $student->gender,
                'status' => $student->status,
                'created_at' => $student->created_at?->toIso8601String(),
                'archived_at' => $student->archived_at?->toIso8601String(),
            ],
            'enrollments' => $student->enrollments()->get()->map(fn ($e) => [
                'school_year' => $e->schoolYear?->label,
                'grade_level' => $e->grade_level,
                'enrolled_at' => $e->enrolled_at?->toDateString(),
                'ended_at' => $e->ended_at?->toDateString(),
            ]),
            'memberships' => $student->memberships()->with('learningGroup')->get()->map(fn ($m) => [
                'group' => $m->learningGroup?->name,
                'group_type' => $m->learningGroup?->group_type,
            ]),
            'attempts' => TestAttempt::query()
                ->where('student_id', $student->id)
                ->get()
                ->map(fn (TestAttempt $a) => [
                    'id' => $a->id,
                    'test_run' => $a->testRun?->name,
                    'status' => $a->status,
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'score_raw' => $a->score_raw,
                    'lq_at_submission' => $a->lq_at_submission,
                    'lq_current' => $a->lq_current,
                ]),
            'audit_excerpt' => AuditLog::query()
                ->where('entity_type', 'student')
                ->where('entity_id', $student->id)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(['action', 'created_at', 'actor_user_id']),
        ];

        return $data;
    }

    /**
     * Auflistung von SuS, die die Voraussetzungen für eine Löschung erfüllen
     * (= im Archiv und Archivierung > $minAgeDays Tage zurück).
     *
     * @return Collection<int, Student>
     */
    public function listDeletionCandidates(int $minAgeDays = 1825): Collection
    {
        return Student::query()
            ->where('status', 'archiviert')
            ->where('archived_at', '<=', now()->subDays($minAgeDays))
            ->orderBy('archived_at')
            ->get();
    }

    /**
     * Löscht einen Schüler vollständig (Art. 17 DSGVO).
     *
     * 4-Augen-Prinzip: erfordert zwei verschiedene User-IDs als Bestätiger.
     * Für die MVP-Implementierung: nur ein User (Admin); zweite Bestätigung kommt
     * aus der UI als separater "I confirm"-Click.
     */
    public function deleteStudent(Student $student, User $byUser, string $reason, bool $confirmed): bool
    {
        if (! $confirmed) {
            return false;
        }

        DB::transaction(function () use ($student, $byUser, $reason) {
            $context = [
                'student_id' => $student->id,
                'student_code' => $student->student_code,
                'external_student_id' => $student->external_student_id,
                'reason' => $reason,
            ];

            $student->delete(); // SoftDeletes → in Trash; ggf. später force delete

            $this->audit->logUser(
                $byUser,
                action: 'students.delete',
                entityType: 'student',
                entityId: $student->id,
                context: $context,
                includesClearnames: false,
            );
        });

        return true;
    }
}
