<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

/**
 * Schüler-Test-Flow (öffentlich, Code-basiert).
 *
 * Sessions:
 *   student_attempt_id  → laufender Versuch
 *   student_started_at  → Server-Startzeit (für Timer)
 */
class StudentTestController extends Controller
{
    public function __construct(private readonly TestEngine $engine) {}

    public function start(Request $request): View|RedirectResponse
    {
        if (Session::has('student_attempt_id')) {
            return redirect()->route('student-test.questions');
        }

        $code = strtoupper(trim((string) $request->query('code', '')));

        return view('student-test.start', [
            'code' => $code,
            'error' => Session::pull('test_error'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login_code' => ['required', 'string', 'size:10'],
        ]);

        $info = $this->engine->loginByCode($data['login_code']);
        if ($info === null) {
            Session::flash('test_error', 'Code unbekannt, gesperrt oder bereits verwendet.');

            return redirect()->route('student-test.start');
        }

        $attempt = $this->engine->startAttempt($info['student'], $info['test_run'], $info['login_code']);

        Session::put('student_attempt_id', $attempt->id);
        Session::put('student_started_at', $attempt->started_at?->timestamp ?? time());

        return redirect()->route('student-test.instructions');
    }

    public function instructions(): View|RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }

        return view('student-test.instructions', [
            'attempt' => $attempt,
            'noticeText' => $attempt->testRun->noticeText?->content
                ?? 'Bitte lies jeden Satz und entscheide, ob er richtig oder falsch ist.',
            'practiceSeconds' => $attempt->testRun->practice_time_seconds,
            'timeLimitSeconds' => $attempt->time_limit_seconds,
        ]);
    }

    public function questions(): View|RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }
        $startedAt = Session::get('student_started_at', $attempt->started_at?->timestamp ?? time());
        $remaining = max(0, $attempt->time_limit_seconds - (time() - $startedAt));

        if ($remaining === 0 && $attempt->status === 'gestartet') {
            $this->engine->submitAttempt($attempt, 'system');

            return redirect()->route('student-test.result');
        }

        return view('student-test.questions', [
            'attempt' => $attempt,
            'questions' => $attempt->questionnaire?->questions ?? collect(),
            'answers' => $attempt->answers()->pluck('given_answer', 'question_id')->all(),
            'remaining' => $remaining,
        ]);
    }

    public function answer(Request $request)
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return response()->json(['ok' => false], 401);
        }
        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'answer' => ['required', 'in:richtig,falsch'],
        ]);

        $ok = $this->engine->saveAnswer($attempt, $data['question_id'], $data['answer']);

        return response()->json(['ok' => $ok]);
    }

    public function submit(): RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }
        $this->engine->submitAttempt($attempt, 'schueler');

        return redirect()->route('student-test.result');
    }

    public function result(): View|RedirectResponse
    {
        $attemptId = Session::get('student_attempt_id');
        if (! $attemptId) {
            return redirect()->route('student-test.start');
        }
        $attempt = TestAttempt::query()->find($attemptId);
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }

        $showScore = (bool) $attempt->testRun->show_score_to_student;
        $hasLq = $attempt->lq_current !== null;

        // Session bereinigen, Test ist abgeschlossen
        Session::forget(['student_attempt_id', 'student_started_at']);

        return view('student-test.result', [
            'attempt' => $attempt,
            'showScore' => $showScore && $hasLq, // Anzeige nur wenn LQ ableitbar
            'hasLq' => $hasLq,
        ]);
    }

    private function currentAttempt(): ?TestAttempt
    {
        $id = Session::get('student_attempt_id');
        if (! $id) {
            return null;
        }

        return TestAttempt::query()->with(['testRun.noticeText', 'questionnaire.questions'])->find($id);
    }
}
