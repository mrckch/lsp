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
 * Importer für klassische SchiLD-NRW CSV-Exporte.
 *
 * Erwartetes Format (Spalten, ;-getrennt):
 *   ID ; Name ; Vorname ; Klasse/Kurs ; Geschlecht
 */
final class SchildCsvImporter implements StudentImporter
{
    public function __construct(
        private readonly CryptoService $crypto,
        private readonly AuditLogger $audit,
    ) {}

    public function key(): string
    {
        return 'schild_csv';
    }

    public function validate(ImportInput $input): ValidationResult
    {
        $rows = $this->readCsv($input);
        $valid = 0;
        $errors = 0;

        $validated = [];
        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $extId = trim($row[0] ?? '');
            $lastName = trim($row[1] ?? '');
            $firstName = trim($row[2] ?? '');
            $groupName = trim($row[3] ?? '');

            if ($lastName === '') {
                $rowErrors[] = 'Nachname fehlt';
            }
            if ($firstName === '') {
                $rowErrors[] = 'Vorname fehlt';
            }
            if ($groupName === '') {
                $rowErrors[] = 'Klasse/Kurs fehlt';
            }

            $isValid = empty($rowErrors);
            if ($isValid) {
                $valid++;
            } else {
                $errors++;
            }

            $validated[] = [
                'row_number' => $i + ($input->ignoreFirstRow ? 2 : 1),
                'raw' => $row,
                'errors' => $rowErrors,
                'valid' => $isValid,
                'external_student_id' => $extId,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'group_name' => $groupName,
                'gender' => $this->mapGender(trim($row[4] ?? '')),
            ];
        }

        return new ValidationResult(
            rows: $validated,
            totalRows: count($validated),
            validRows: $valid,
            errorRows: $errors,
        );
    }

    public function diff(ImportInput $input, int $schoolYearId, string $groupType): DiffSet
    {
        $validation = $this->validate($input);

        $job = ImportJob::create([
            'import_source_id' => null,
            'school_year_id' => $schoolYearId,
            'group_type' => $groupType,
            'filename' => $input->filename,
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
                    'payload' => ['raw' => $row['raw']],
                ]);
                $errorCount++;

                continue;
            }

            $extId = $row['external_student_id'];
            $importedExternalIds[] = $extId;

            $existing = $extId !== ''
                ? Student::query()->where('external_student_id', $extId)->where('external_id_source', 'schild')->first()
                : null;

            if ($existing) {
                // Hat sich was geändert? (Klassenwechsel oder Namensänderung)
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

        // Archivkandidaten: aktive SuS mit Enrollment im Schuljahr, die NICHT im Import sind
        $archiveCandidates = Student::query()
            ->where('students.status', 'aktiv')
            ->where('students.external_id_source', 'schild')
            ->whereNotIn('students.external_student_id', array_filter($importedExternalIds))
            ->whereExists(function ($q) use ($schoolYearId) {
                $q->select(DB::raw(1))
                    ->from('student_enrollments')
                    ->whereColumn('student_enrollments.student_id', 'students.id')
                    ->where('student_enrollments.school_year_id', $schoolYearId);
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
                'payload' => ['reason' => 'nicht im aktuellen Import enthalten'],
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

        DB::transaction(function () use ($job, $entries, $decisions, $shortName, &$imported, &$updated, &$archived, &$skipped, &$failed) {
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
                        'archive' => $this->doArchive($entry) && $archived++,
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
                'filename' => $job->filename ?? 'unknown',
                'source_key' => 'schild_csv',
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
            context: compact('imported', 'updated', 'archived', 'skipped', 'failed'),
            includesClearnames: true,
        );

        return new CommitResult($imported, $updated, $archived, $skipped, $failed);
    }

    // ── private ──────────────────────────────────────────────────────────────

    private function readCsv(ImportInput $input): array
    {
        $rows = [];
        $handle = fopen($input->filePath, 'r');
        if (! $handle) {
            return [];
        }
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $line = 0;
        while (($row = fgetcsv($handle, 0, $input->delimiter, '"', '')) !== false) {
            $line++;
            if ($input->ignoreFirstRow && $line === 1) {
                continue;
            }
            if (count(array_filter($row, fn ($v) => $v !== '' && $v !== null)) === 0) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function mapGender(string $raw): string
    {
        return match (strtolower($raw)) {
            'm', 'männlich', 'maennlich', 'male' => 'm',
            'w', 'f', 'weiblich', 'female' => 'w',
            'd', 'divers' => 'd',
            default => 'unbekannt',
        };
    }

    private function detectChange(Student $student, array $row, int $schoolYearId): ?array
    {
        $changes = [];

        // Klarnamen-Vergleich nur wenn entsperrt
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

        // Klassenwechsel?
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

        return empty($changes) ? null : $changes;
    }

    private function payloadFromRow(array $row, ?array $changes): array
    {
        // Klarnamen NICHT roh in payload speichern (nur Hash zur Identifikation)
        return [
            'row' => [
                'external_student_id' => $row['external_student_id'],
                'first_name_hash' => substr(hash('sha256', $row['first_name']), 0, 16),
                'last_name_hash' => substr(hash('sha256', $row['last_name']), 0, 16),
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'gender' => $row['gender'],
                'group_name' => $row['group_name'],
            ],
            'changes' => $changes,
        ];
    }

    private function doCreate(ImportDiffEntry $entry, array $row, ImportJob $job, string $shortName): bool
    {
        $student = new Student;
        $student->external_student_id = $row['external_student_id'] ?: null;
        $student->external_id_source = 'schild';
        $student->student_code = Student::generateUniqueCode($shortName);
        $student->gender = $row['gender'];
        $student->status = 'aktiv';
        // verschlüsselt setzen
        $student->first_name_encrypted = $row['first_name'];
        $student->last_name_encrypted = $row['last_name'];
        $student->save();

        $this->ensureEnrollment($student->id, $job->school_year_id, null);
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
        // Nur Felder updaten, deren Klartext-Wert tatsächlich da ist
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

        $this->ensureEnrollment($student->id, $job->school_year_id, null);
        $groupId = $this->ensureGroup($job->school_year_id, $row['group_name'], $job->group_type);
        $this->ensureMembership($student->id, $groupId, $job->school_year_id);

        return true;
    }

    private function doArchive(ImportDiffEntry $entry): bool
    {
        $student = Student::query()->find($entry->matched_student_id);
        if (! $student) {
            return false;
        }
        $student->archive('Nicht im aktuellen SchiLD-Import enthalten.');

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
