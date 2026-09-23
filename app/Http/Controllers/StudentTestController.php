<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use App\Domain\NoticeText\Models\NoticeText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

/**
 * Schüler-Test-Flow (öffentlich, Code-basiert).
 *
 * Ablauf: Code → Hinweise → (Vorabübung) → „Test starten“ → Aufgaben → Ergebnis.
 * Der Haupttest-Timer läuft serverseitig ab `test_attempts.main_started_at`.
 *
 * Sessions:
 *   student_attempt_id           → laufender Versuch
 *   student_practice_started_at  → Startzeit der Vorabübung (Reload setzt sie nicht zurück)
 */
class StudentTestController extends Controller
{
    public function __construct(private readonly TestEngine $engine) {}

    public function start(Request $request): View|RedirectResponse
    {
        $code = strtoupper(trim((string) $request->query('code', '')));

        if (Session::has('student_attempt_id')) {
            // Geteiltes Tablet: Ein anderer QR-Code löst die alte Sitzung ab.
            // Derselbe Code (oder keiner) führt zurück in den laufenden Test.
            $usedCode = TestAttempt::query()->whereKey(Session::get('student_attempt_id'))->value('login_code_used');
            if ($code === '' || $code === $usedCode) {
                return redirect()->route('student-test.questions');
            }
            Session::forget(['student_attempt_id', 'student_practice_started_at']);
        }

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
            $status = StudentLoginCode::query()->where('login_code', strtoupper($data['login_code']))->value('status');
            Session::flash('test_error', $status === 'verbraucht'
                ? 'Dein Test wurde bereits abgegeben. Du kannst dich damit nicht noch einmal anmelden.'
                : 'Code unbekannt, gesperrt oder bereits verwendet.');

            return redirect()->route('student-test.start');
        }

        $attempt = $this->engine->startAttempt($info['student'], $info['test_run'], $info['login_code']);

        // Wiederanmeldung in denselben Versuch (z. B. Tab geschlossen): Übungszeit läuft weiter
        if (Session::get('student_attempt_id') !== $attempt->id) {
            Session::forget('student_practice_started_at');
        }
        Session::put('student_attempt_id', $attempt->id);

        return redirect()->route('student-test.instructions');
    }

    public function instructions(): View|RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }
        if ($attempt->main_started_at !== null || $attempt->status !== 'gestartet') {
            return redirect()->route('student-test.questions');
        }

        return view('student-test.instructions', [
            'attempt' => $attempt,
            'noticeText' => $attempt->testRun->noticeText?->content
                ?? NoticeText::defaultText()?->content
                ?? 'Bitte lies jeden Satz und entscheide, ob er richtig oder falsch ist.',
            'hasPractice' => $this->hasPractice($attempt),
            'practiceSeconds' => (int) $attempt->testRun->practice_time_seconds,
            'timeLimitSeconds' => $attempt->time_limit_seconds,
        ]);
    }

    /**
     * Vorabübung: Übungsfragen des Fragebogens mit eigenem Countdown.
     * Nicht gewertet, nichts wird gespeichert – die Rückmeldung erfolgt im Browser.
     */
    public function practice(): View|RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }
        if ($attempt->main_started_at !== null || $attempt->status !== 'gestartet') {
            return redirect()->route('student-test.questions');
        }
        if (! $this->hasPractice($attempt)) {
            return redirect()->route('student-test.instructions');
        }

        if (! Session::has('student_practice_started_at')) {
            Session::put('student_practice_started_at', time());
        }
        $seconds = (int) $attempt->testRun->practice_time_seconds;
        $remaining = max(0, $seconds - (time() - (int) Session::get('student_practice_started_at')));

        return view('student-test.practice', [
            'attempt' => $attempt,
            'questions' => $attempt->questionnaire->practiceQuestions,
            'remaining' => $remaining,
        ]);
    }

    /**
     * „Test starten“: startet den Haupttest-Timer.
     */
    public function begin(): RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }

        $this->engine->beginMain($attempt);
        Session::forget('student_practice_started_at');

        return redirect()->route('student-test.questions');
    }

    public function questions(): View|RedirectResponse
    {
        $attempt = $this->currentAttempt();
        if (! $attempt) {
            return redirect()->route('student-test.start');
        }
        if ($attempt->status !== 'gestartet') {
            return redirect()->route('student-test.result');
        }
        if ($attempt->main_started_at === null) {
            return redirect()->route('student-test.instructions');
        }

        $remaining = $attempt->remainingSeconds();
        if ($remaining === 0) {
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

        // Vor „Test starten“ keine Antworten annehmen
        if ($attempt->main_started_at === null) {
            return response()->json(['ok' => false], 422);
        }

        $ok = $this->engine->saveAnswer($attempt, $data['question_id'], $data['answer']);

        // ended: Versuch ist beendet (Zeit abgelaufen / durch Lehrkraft) → Seite neu laden
        $ended = ! $ok && ($attempt->refresh()->status !== 'gestartet' || $attempt->remainingSeconds() === 0);

        return response()->json(['ok' => $ok, 'ended' => $ended]);
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
        if ($attempt->status === 'gestartet') {
            return redirect()->route('student-test.questions');
        }

        $showScore = (bool) $attempt->testRun->show_score_to_student;
        $hasLq = $attempt->lq_current !== null;

        // Session bereinigen, Test ist abgeschlossen
        Session::forget(['student_attempt_id', 'student_practice_started_at']);

        return view('student-test.result', [
            'attempt' => $attempt,
            'showScore' => $showScore && $hasLq, // Anzeige nur wenn LQ ableitbar
            'hasLq' => $hasLq,
        ]);
    }

    private function hasPractice(TestAttempt $attempt): bool
    {
        return (int) $attempt->testRun->practice_time_seconds > 0
            && ($attempt->questionnaire?->practiceQuestions->isNotEmpty() ?? false);
    }

    /**
     * Laufender Versuch aus der Session. Ein von der Lehrkraft zurückgesetzter
     * Versuch beendet die Session – der Schüler meldet sich mit demselben Code neu an.
     */
    private function currentAttempt(): ?TestAttempt
    {
        $id = Session::get('student_attempt_id');
        if (! $id) {
            return null;
        }

        $attempt = TestAttempt::query()
            ->with(['testRun.noticeText', 'questionnaire.questions', 'questionnaire.practiceQuestions'])
            ->find($id);

        if ($attempt?->status === 'zurueckgesetzt') {
            Session::forget(['student_attempt_id', 'student_practice_started_at']);
            Session::flash('test_error', 'Dein Test wurde zurückgesetzt. Bitte melde dich mit deinem Code erneut an.');

            return null;
        }

        return $attempt;
    }
}
