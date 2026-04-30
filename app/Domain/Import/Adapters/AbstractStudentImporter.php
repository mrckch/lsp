<?php

declare(strict_types=1);

namespace App\Domain\Import\Adapters;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crypto\CryptoService;
use App\Domain\Import\Contracts\StudentImporter;
use App\Domain\Import\DTOs\CommitResult;
use App\Domain\Import\DTOs\DiffSet;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\DTOs\ValidationResult;
use App\Domain\Import\Models\ImportDiffEntry;
use App\Domain\Import\Models\ImportJob;
use App\Domain\School\Models\LearningGroup;
use App\Domain\Student\Models\Student;
use App\Domain\Student\Models\StudentEnrollment;
use App\Domain\Student\Models\StudentGroupMembership;
use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;

/**
 * Gemeinsame Basisklasse für alle Schüler-Importer (SchildCsv, SvwsApi, …).
 *
 * Subklassen liefern nur:
 *  - key(): identifizierender Source-Key
 *  - externalIdSource(): Wert für students.external_id_source (z. B. 'schild', 'svws')
 *  - fetchRows(ImportInput): normalisierte Rohzeilen (s. row-Format unten)
 *  - sourceLabel() (optional): wird als Standardname für ImportJob.filename genutzt
 *
 * Alle anderen Phasen (Field-Validation, Diff, Commit, Helpers) sind hier
 * gemeinsam implementiert. Eine ImportSource (sourceId) wird, wenn vorhanden,
 * im ImportJob hinterlegt — CSV-Importer arbeiten ohne, API-Importer mit.
 *
 * Erwartetes row-Format aus fetchRows:
 *   row_number          int      Zeilen- bzw. Sortierungsnummer
 *   external_student_id string   ID aus der Quelle (z. B. SchiLD/SVWS-ID)
 *   last_name           string   Nachname (Klartext)
 *   first_name          string   Vorname (Klartext)
 *   gender              string   m|w|d|unbekannt
 *   group_name          string   Name der Klasse/Lerngruppe (z. B. "5a")
 *   jahrgang            string   optional, z. B. "05" (für Enrollment-grade_level)
 *   raw                 mixed    Quellen-spezifischer Original-Datensatz (für Audit/Debug)
 */
abstract class AbstractStudentImporter implements StudentImporter
{
    public function __construct(
        protected readonly CryptoService $crypto,
        protected readonly AuditLogger $audit,
    ) {}

    abstract public function key(): string;

    abstract protected function externalIdSource(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract protected function fetchRows(ImportInput $input): array;

    /** Standard-Filename für ImportJob bei API-Importern; CSV überschreibt mit basename. */
    protected function defaultJobFilename(ImportInput $input): string
    {
        return $input->filename;
    }

    public function validate(ImportInput $input): ValidationResult
    {
        $rows = $this->fetchRows($input);

        $valid = 0;
        $errors = 0;
        foreach ($rows as &$row) {
            $rowErrors = [];
            if (($row['last_name'] ?? '') === '') {
                $rowErrors[] = 'Nachname fehlt';
            }
            if (($row['first_name'] ?? '') === '') {
                $rowErrors[] = 'Vorname fehlt';
            }
            if (($row['group_name'] ?? '') === '') {
                $rowErrors[] = 'Klasse/Kurs fehlt';
            }
            $row['errors'] = $rowErrors;
            $row['valid'] = $rowErrors === [];

            if ($row['valid']) {
                $valid++;
            } else {
                $errors++;
            }
        }
        unset($row);

        return new ValidationResult(
            rows: $rows,
            totalRows: count($rows),
            validRows: $valid,
            errorRows: $errors,
        );
    }

    public function diff(ImportInput $input, int $schoolYearId, string $groupType): DiffSet
    {
        $validation = $this->validate($input);
        $extSource = $this->externalIdSource();

        $job = ImportJob::create([
            'import_source_id' => $input->sourceId,
            'school_year_id' => $schoolYearId,
            'group_type' => $groupType,
            'filename' => $this->defaultJobFilename($input),
            'status' => 'validated',
            'started_by_user_id' => auth()->id() ?? 1,
            'started_at' => now(),
            'validated_at' => now(),
        ]);

        $createCount = $updateCount = $skipCount = $errorCount = 0;
        $importedExternalIds = [];

        foreach ($validation->rows as $row) {
            if (! $row['valid']) {
                ImportDiffEntry::create([
                    'import_job_id' => $job->id,
                    'row_number' => $row['row_number'],
                    'external_student_id' => $row['external_student_id'] ?: null,
                    'action' => 'error',
                    'errors' => $row['errors'],
                    'payload' => ['raw' => $row['raw'] ?? $row],
                ]);
                $errorCount++;

                continue;
            }

            $extId = $row['external_student_id'];
            $importedExternalIds[] = $extId;

            $existing = $extId !== ''
                ? Student::query()
                    ->where('external_student_id', $extId)
                    ->where('external_id_source', $extSource)
                    ->first()
                : null;

            if ($existing) {
                $changed = $this->detectChange($existing, $row, $schoolYearId);
                if ($changed === null) {
                    $action = 'skip';
                    $skipCount++;
                } else {
                    $action = 'update';
                    $updateCount++;
                }

                ImportDiffEntry::create([
                    'import_job_id' => $job->id,
                    'row_number' => $row['row_number'],
                    'external_student_id' => $extId,
                    'action' => $action,
                    'matched_student_id' => $existing->id,
                    'payload' => $this->payloadFromRow($row, $changed),
                ]);
            } else {
                ImportDiffEntry::create([
                    'import_job_id' => $job->id,
                    'row_number' => $row['row_number'],
                    'external_student_id' => $extId ?: null,
                    'action' => 'create',
                    'payload' => $this->payloadFromRow($row, null),
                ]);
                $createCount++;
            }
        }

        // Archivkandidaten: aktive SuS mit Enrollment im Schuljahr, die NICHT im Import sind.
        // Bei aktivem Stufenfilter werden nur SuS mit Enrollment-grade_level im Filter
        // berücksichtigt — Schüler außerhalb der Filterstufe bleiben unangetastet.
        $gradeFilter = $input->gradeFilter;
        $archiveCandidates = Student::query()
            ->where('students.status', 'aktiv')
            ->where('students.external_id_source', $extSource)
            ->whereNotIn('students.external_student_id', array_filter($importedExternalIds))
            ->whereExists(function ($q) use ($schoolYearId, $gradeFilter) {
                $q->select(DB::raw(1))
                    ->from('student_enrollments')
                    ->whereColumn('student_enrollments.student_id', 'students.id')
                    ->where('student_enrollments.school_year_id', $schoolYearId);
                if ($gradeFilter !== null && $gradeFilter !== []) {
                    $q->whereIn('student_enrollments.grade_level', $gradeFilter);
                }
            })
            ->get();

        $archiveCount = 0;
        foreach ($archiveCandidates as $candidate) {
            ImportDiffEntry::create([
                'import_job_id' => $job->id,
                'row_number' => 0,
                'external_student_id' => $candidate->external_student_id,
                'action' => 'archive',
                'matched_student_id' => $candidate->id,
                'payload' => ['reason' => 'nicht im aktuellen '.$this->key().'-Import enthalten'],
            ]);
            $archiveCount++;
        }

        $stats = [
            'total' => $validation->totalRows + $archiveCount,
            'create' => $createCount,
            'update' => $updateCount,
            'archive' => $archiveCount,
            'skip' => $skipCount,
            'error' => $errorCount,
        ];

        $job->update(['status' => 'diff_ready', 'stats' => $stats]);

        return new DiffSet(
            importJobId: $job->id,
            totalEntries: $validation->totalRows + $archiveCount,
            createCount: $createCount,
            updateCount: $updateCount,
            archiveCount: $archiveCount,
            skipCount: $skipCount,
            errorCount: $errorCount,
        );
    }

    public function commit(int $importJobId, array $decisions): CommitResult
    {
        if (! $this->crypto->isUnlocked()) {
            throw new \RuntimeException('Klarnamen-Session muss entsperrt sein.');
        }

        $job = ImportJob::query()->findOrFail($importJobId);
        $entries = ImportDiffEntry::query()->where('import_job_id', $importJobId)->get();

        $imported = $updated = $archived = $skipped = $failed = 0;
        $shortName = AppSetting::singleton()->school_short_name ?: 'LSP';
        $sourceKey = $this->key();

        DB::transaction(function () use ($job, $entries, $decisions, $shortName, $sourceKey, &$imported, &$updated, &$archived, &$skipped, &$failed) {
            foreach ($entries as $entry) {
                $decision = $decisions[$entry->id] ?? $entry->admin_decision;

                if ($decision === 'exclude' || $entry->action === 'error' || $entry->action === 'skip') {
                    $skipped++;

                    continue;
                }

                $payload = $entry->payload ?? [];
                $row = $payload['row'] ?? null;

                try {
                    match ($entry->action) {
                        'create' => $this->doCreate($entry, $row, $job, $shortName) && $imported++,
                        'update' => $this->doUpdate($entry, $row, $job) && $updated++,
                        'archive' => $this->doArchive($entry, $sourceKey) && $archived++,
                        default => null,
                    };
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                }
            }

            $job->update([
                'status' => 'committed',
                'committed_at' => now(),
                'stats' => [
                    ...$job->stats ?? [],
                    'committed' => [
                        'imported' => $imported,
                        'updated' => $updated,
                        'archived' => $archived,
                        'skipped' => $skipped,
                        'failed' => $failed,
                    ],
                ],
            ]);

            DB::table('student_imports')->insert([
                'import_job_id' => $job->id,
                'school_year_id' => $job->school_year_id,
                'filename' => $job->filename ?? $sourceKey,
                'source_key' => $sourceKey,
                'rows_total' => $entries->count(),
                'rows_imported' => $imported,
                'rows_updated' => $updated,
                'rows_archived' => $archived,
                'rows_skipped' => $skipped,
                'imported_by_user_id' => auth()->id() ?? $job->started_by_user_id,
                'imported_at' => now(),
            ]);
        });

        $this->audit->logUser(
            auth()->user() ?? \App\Models\User::find($job->started_by_user_id),
            action: 'import.committed',
            entityType: 'import_job',
            entityId: $job->id,
            context: compact('imported', 'updated', 'archived', 'skipped', 'failed') + ['source' => $sourceKey],
            includesClearnames: true,
        );

        return new CommitResult($imported, $updated, $archived, $skipped, $failed);
    }

    // ── Shared helpers ───────────────────────────────────────────────────────

    protected function mapGender(string $raw): string
    {
        return match (strtolower($raw)) {
            'm', 'männlich', 'maennlich', 'male' => 'm',
            'w', 'f', 'weiblich', 'female' => 'w',
            'd', 'divers' => 'd',
            default => 'unbekannt',
        };
    }

    /**
     * @return array<string,mixed>|null  null wenn keine Änderung; sonst Liste der Felder
     */
    protected function detectChange(Student $student, array $row, int $schoolYearId): ?array
    {
        $changes = [];

        if ($this->crypto->isUnlocked()) {
            if ($student->first_name_encrypted !== $row['first_name']) {
                $changes['first_name'] = $row['first_name'];
            }
            if ($student->last_name_encrypted !== $row['last_name']) {
                $changes['last_name'] = $row['last_name'];
            }
        }

        if ($student->gender !== $row['gender']) {
            $changes['gender'] = $row['gender'];
        }

        $currentGroup = StudentGroupMembership::query()
            ->where('student_id', $student->id)
            ->where('school_year_id', $schoolYearId)
            ->first();
        $currentGroupName = $currentGroup
            ? LearningGroup::query()->find($currentGroup->learning_group_id)?->name
            : null;

        if ($currentGroupName !== $row['group_name']) {
            $changes['group_name'] = $row['group_name'];
        }

        return $changes === [] ? null : $changes;
    }

    protected function payloadFromRow(array $row, ?array $changes): array
    {
        return [
            'row' => [
                'external_student_id' => $row['external_student_id'],
                'first_name_hash' => substr(hash('sha256', $row['first_name']), 0, 16),
                'last_name_hash' => substr(hash('sha256', $row['last_name']), 0, 16),
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'gender' => $row['gender'],
                'group_name' => $row['group_name'],
                'jahrgang' => $row['jahrgang'] ?? null,
            ],
            'changes' => $changes,
        ];
    }

    private function doCreate(ImportDiffEntry $entry, array $row, ImportJob $job, string $shortName): bool
    {
        $student = new Student;
        $student->external_student_id = $row['external_student_id'] ?: null;
        $student->external_id_source = $this->externalIdSource();
        $student->student_code = Student::generateUniqueCode($shortName);
        $student->gender = $row['gender'];
        $student->status = 'aktiv';
        $student->first_name_encrypted = $row['first_name'];
        $student->last_name_encrypted = $row['last_name'];
        $student->save();

        $this->ensureEnrollment($student->id, $job->school_year_id, $row['jahrgang'] ?? null);
        $groupId = $this->ensureGroup($job->school_year_id, $row['group_name'], $job->group_type);
        $this->ensureMembership($student->id, $groupId, $job->school_year_id);

        return true;
    }

    private function doUpdate(ImportDiffEntry $entry, array $row, ImportJob $job): bool
    {
        $student = Student::query()->find($entry->matched_student_id);
        if (! $student) {
            return false;
        }
        if (isset($row['first_name']) && $row['first_name'] !== '') {
            $student->first_name_encrypted = $row['first_name'];
        }
        if (isset($row['last_name']) && $row['last_name'] !== '') {
            $student->last_name_encrypted = $row['last_name'];
        }
        if (isset($row['gender'])) {
            $student->gender = $row['gender'];
        }
        $student->save();

        $this->ensureEnrollment($student->id, $job->school_year_id, $row['jahrgang'] ?? null);
        $groupId = $this->ensureGroup($job->school_year_id, $row['group_name'], $job->group_type);
        $this->ensureMembership($student->id, $groupId, $job->school_year_id);

        return true;
    }

    private function doArchive(ImportDiffEntry $entry, string $sourceKey): bool
    {
        $student = Student::query()->find($entry->matched_student_id);
        if (! $student) {
            return false;
        }
        $student->archive("Nicht im aktuellen $sourceKey-Import enthalten.");

        return true;
    }

    private function ensureEnrollment(int $studentId, int $schoolYearId, ?string $gradeLevel): void
    {
        $existing = StudentEnrollment::query()
            ->where('student_id', $studentId)
            ->where('school_year_id', $schoolYearId)
            ->first();
        if ($existing) {
            if ($gradeLevel !== null && $existing->grade_level !== $gradeLevel) {
                $existing->update(['grade_level' => $gradeLevel]);
            }

            return;
        }
        StudentEnrollment::create([
            'student_id' => $studentId,
            'school_year_id' => $schoolYearId,
            'grade_level' => $gradeLevel,
            'enrolled_at' => now()->toDateString(),
        ]);
    }

    private function ensureGroup(int $schoolYearId, string $name, string $groupType): int
    {
        $group = LearningGroup::query()
            ->where('school_year_id', $schoolYearId)
            ->where('name', $name)
            ->where('group_type', $groupType)
            ->first();

        if ($group) {
            return (int) $group->id;
        }

        return (int) LearningGroup::create([
            'school_year_id' => $schoolYearId,
            'name' => $name,
            'group_type' => $groupType,
            'is_active' => true,
        ])->id;
    }

    private function ensureMembership(int $studentId, int $groupId, int $schoolYearId): void
    {
        StudentGroupMembership::query()->firstOrCreate(
            ['student_id' => $studentId, 'learning_group_id' => $groupId],
            ['school_year_id' => $schoolYearId],
        );
    }
}
