<?php

declare(strict_types=1);

namespace App\Domain\Attempt;

use App\Domain\Attempt\Models\AttemptAnswer;
use App\Domain\Attempt\Models\AttemptLqHistory;
use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\NormTable\LqResolver;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use Illuminate\Support\Facades\DB;

/**
 * Lebenszyklus eines Test-Versuchs.
 */
final class TestEngine
{
    public function __construct(private readonly LqResolver $lqResolver) {}

    /**
     * Findet aktiven Login-Code → liefert Student + TestRun + offene Versuche.
     */
    public function loginByCode(string $code): ?array
    {
        $loginCode = StudentLoginCode::query()
            ->where('login_code', strtoupper($code))
            ->first();
        if ($loginCode === null) {
            return null;
        }

        $run = $loginCode->testRun;
        if ($run === null || $run->status !== 'aktiv') {
            return null;
        }
        if (in_array($loginCode->status, ['verbraucht', 'gesperrt'], true)) {
            return null;
        }

        return [
            'student' => $loginCode->student,
            'test_run' => $run,
            'login_code' => $loginCode,
        ];
    }

    /**
     * Startet (oder reaktiviert) den Test-Versuch dieses Schülers in diesem Run.
     */
    public function startAttempt(Student $student, TestRun $run, StudentLoginCode $loginCode): TestAttempt
    {
        $existing = TestAttempt::query()
            ->where('student_id', $student->id)
            ->where('test_run_id', $run->id)
            ->where('status', 'gestartet')
            ->first();

        if ($existing) {
            return $existing;
        }

        $attempt = TestAttempt::create([
            'student_id' => $student->id,
            'test_run_id' => $run->id,
            'questionnaire_id' => $run->questionnaire_id,
            'parallel_form' => $run->questionnaire?->parallel_form,
            'norm_table_id' => $run->norm_table_id,
            'status' => 'gestartet',
            'started_at' => now(),
            'time_limit_seconds' => $run->time_limit_seconds,
            'login_code_used' => $loginCode->login_code,
        ]);

        $loginCode->update(['status' => 'in_bearbeitung']);

        return $attempt;
    }

    /**
     * Speichert oder aktualisiert eine Antwort.
     */
    public function saveAnswer(TestAttempt $attempt, int $questionId, string $answer): bool
    {
        if ($attempt->status !== 'gestartet') {
            return false;
        }

        $question = QuestionnaireQuestion::query()->find($questionId);
        if ($question === null || $question->questionnaire_id !== $attempt->questionnaire_id) {
            return false;
        }

        $isCorrect = $answer === $question->correct_answer;

        AttemptAnswer::query()->updateOrCreate(
            ['test_attempt_id' => $attempt->id, 'question_id' => $questionId],
            ['given_answer' => $answer, 'is_correct' => $isCorrect, 'answered_at' => now()],
        );

        return true;
    }

    /**
     * Schließt den Versuch ab (manuell oder Timeout).
     */
    public function submitAttempt(TestAttempt $attempt, string $endedBy = 'schueler'): TestAttempt
    {
        if ($attempt->status !== 'gestartet') {
            return $attempt;
        }

        $score = (int) AttemptAnswer::query()
            ->where('test_attempt_id', $attempt->id)
            ->where('is_correct', true)
            ->count();

        $status = $endedBy === 'system' ? 'zeit_abgelaufen' : 'abgegeben';

        DB::transaction(function () use ($attempt, $score, $status, $endedBy) {
            $lq = $this->lqResolver->resolve(
                $score,
                $attempt->student->gender,
                $attempt->normTable,
            );

            $attempt->update([
                'status' => $status,
                'submitted_at' => now(),
                'score_raw' => $score,
                'lq_at_submission' => $lq,
                'lq_current' => $lq,
                'lq_calculated_at' => now(),
                'ended_by' => $endedBy,
            ]);

            AttemptLqHistory::create([
                'test_attempt_id' => $attempt->id,
                'norm_table_id' => $attempt->norm_table_id,
                'lq_value' => $lq,
                'reason' => 'initial',
            ]);

            // Login-Code als verbraucht markieren
            StudentLoginCode::query()
                ->where('student_id', $attempt->student_id)
                ->where('test_run_id', $attempt->test_run_id)
                ->update(['status' => 'verbraucht', 'consumed_at' => now()]);
        });

        return $attempt->refresh();
    }

    /**
     * Setzt einen Versuch zurück (Admin/Lehrkraft) – Antworten werden gelöscht,
     * Schüler kann mit gleichem Code neu starten.
     */
    public function resetAttempt(TestAttempt $attempt, int $byUserId, ?string $reason = null): void
    {
        DB::transaction(function () use ($attempt, $byUserId, $reason) {
            $attempt->answers()->delete();
            $attempt->update([
                'status' => 'zurueckgesetzt',
                'reset_by_user_id' => $byUserId,
                'reset_reason' => $reason,
            ]);

            StudentLoginCode::query()
                ->where('student_id', $attempt->student_id)
                ->where('test_run_id', $attempt->test_run_id)
                ->update([
                    'status' => 'aktiv',
                    'consumed_at' => null,
                    'reset_at' => now(),
                    'reset_by_user_id' => $byUserId,
                ]);
        });
    }

    /**
     * Berechnet den LQ neu (z. B. nach Norm-Tabellen-Update).
     */
    public function recalculateLq(TestAttempt $attempt, ?NormTable $newNorm = null, string $reason = 'manual_recalc'): void
    {
        $norm = $newNorm ?? $attempt->normTable;
        $lq = $this->lqResolver->resolve(
            $attempt->score_raw,
            $attempt->student->gender,
            $norm,
        );

        $attempt->update([
            'lq_current' => $lq,
            'lq_calculated_at' => now(),
            'norm_table_id' => $norm?->id ?? $attempt->norm_table_id,
        ]);

        AttemptLqHistory::create([
            'test_attempt_id' => $attempt->id,
            'norm_table_id' => $norm?->id,
            'lq_value' => $lq,
            'reason' => $reason,
        ]);
    }

    /**
     * Generiert Login-Codes für alle SuS in den Lerngruppen des Test-Runs.
     */
    public function issueLoginCodes(TestRun $run): int
    {
        $count = 0;
        $studentIds = DB::table('test_run_groups as trg')
            ->join('student_group_memberships as sgm', 'sgm.learning_group_id', '=', 'trg.learning_group_id')
            ->where('trg.test_run_id', $run->id)
            ->pluck('sgm.student_id')
            ->unique();

        foreach ($studentIds as $studentId) {
            $existing = StudentLoginCode::query()
                ->where('student_id', $studentId)
                ->where('test_run_id', $run->id)
                ->first();
            if ($existing) {
                continue;
            }
            StudentLoginCode::create([
                'student_id' => $studentId,
                'test_run_id' => $run->id,
                'login_code' => StudentLoginCode::generateUniqueCode(),
                'status' => 'aktiv',
                'issued_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Rotiert alle aktiven Login-Codes eines TestRuns auf neue Codes.
     *
     * Anwendungsfall: Codes wurden vor dem Test verloren / weitergegeben /
     * geleakt — Admin will einen sauberen Neustart, ohne den TestRun
     * komplett neu anlegen zu müssen.
     *
     * Bestehende Versuche bleiben erhalten; Codes mit Status 'in_bearbeitung'
     * oder 'verbraucht' werden NICHT angetastet (laufende/abgeschlossene
     * Tests).
     *
     * @return int Anzahl rotierter Codes
     */
    public function regenerateActiveLoginCodes(TestRun $run): int
    {
        $count = 0;
        DB::transaction(function () use ($run, &$count) {
            $codes = StudentLoginCode::query()
                ->where('test_run_id', $run->id)
                ->where('status', 'aktiv')
                ->lockForUpdate()
                ->get();

            foreach ($codes as $code) {
                $code->update([
                    'login_code' => StudentLoginCode::generateUniqueCode(),
                    'issued_at' => now(),
                ]);
                $count++;
            }
        });

        return $count;
    }
}
