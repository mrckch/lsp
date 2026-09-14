@extends('student-test.layout')
@section('title', 'Aufgaben')
@section('content')
    <div class="timer" id="timer">{{ $remaining }}s</div>
    <div class="save-status" id="save-status" hidden></div>
    <div class="card">
        <p class="label">Markiere bei jedem Satz, ob er <strong>richtig</strong> oder <strong>falsch</strong> ist.</p>
        @foreach($questions as $i => $q)
            <div class="question" data-qid="{{ $q->id }}">
                <span class="label" style="min-width:2rem;">{{ $i + 1 }}.</span>
                <span class="q-text">{{ $q->question_text }}</span>
                <span class="q-actions">
                    <button type="button" class="btn btn-r {{ ($answers[$q->id] ?? null) === 'richtig' ? 'active' : '' }}"
                            data-answer="richtig">richtig</button>
                    <button type="button" class="btn btn-f {{ ($answers[$q->id] ?? null) === 'falsch' ? 'active' : '' }}"
                            data-answer="falsch">falsch</button>
                </span>
            </div>
        @endforeach
    </div>
    <form method="POST" action="{{ route('student-test.submit') }}" id="submit-form">
        @csrf
        <button class="btn btn-block" type="submit">Test abgeben</button>
    </form>

@push('scripts')
<script>
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const answerUrl = "{{ route('student-test.answer') }}";
    const questionsUrl = "{{ route('student-test.questions') }}";
    let remaining = {{ $remaining }};
    const timer = document.getElementById('timer');
    const saveStatus = document.getElementById('save-status');
    const submitForm = document.getElementById('submit-form');

    // Noch nicht vom Server bestätigte Antworten (question_id → Antwort).
    // Netzwerkfehler, 429 und 5xx werden automatisch wiederholt, damit keine
    // Antwort still verloren geht. Zeilen mit offener Antwort sind markiert.
    const pending = new Map();
    const inFlight = new Set();
    let hadFailure = false;
    let sessionLost = false;

    function updateStatus() {
        if (sessionLost) {
            saveStatus.textContent = 'Sitzung abgelaufen – bitte gib deiner Lehrkraft Bescheid.';
            saveStatus.hidden = false;
        } else if (hadFailure && pending.size > 0) {
            saveStatus.textContent = 'Verbindung wackelt – Antworten werden gespeichert …';
            saveStatus.hidden = false;
        } else {
            saveStatus.hidden = true;
            if (pending.size === 0) hadFailure = false;
        }
    }

    async function send(qid, retryCount = 0) {
        if (inFlight.has(qid) || sessionLost || !pending.has(qid)) return;
        const answer = pending.get(qid);
        inFlight.add(qid);
        let retry = false;
        try {
            const res = await fetch(answerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ question_id: parseInt(qid, 10), answer: answer }),
            });
            if (res.status === 401 || res.status === 419) {
                sessionLost = true;
            } else if (res.ok || res.status === 422) {
                // gespeichert – oder nicht speicherbar (z. B. Test schon abgegeben): nicht wiederholen
                if (pending.get(qid) === answer) pending.delete(qid);
            } else {
                retry = true; // 429, 5xx
            }
        } catch (e) {
            retry = true; // Netzwerkfehler
        } finally {
            inFlight.delete(qid);
        }

        const row = document.querySelector('.question[data-qid="' + qid + '"]');
        if (!pending.has(qid)) {
            row?.classList.remove('unsaved');
        } else if (retry) {
            hadFailure = true;
            setTimeout(() => send(qid, retryCount + 1), Math.min(1000 * 2 ** retryCount, 10000));
        } else if (!sessionLost) {
            send(qid); // während des Requests umentschieden → neuen Stand senden
        }
        updateStatus();
    }

    // Wartet (begrenzt), bis alle offenen Antworten gespeichert sind.
    function flushAnswers(maxMs) {
        pending.forEach((_, qid) => send(qid));
        const started = Date.now();
        return new Promise(resolve => {
            (function check() {
                if (pending.size === 0 || sessionLost || Date.now() - started >= maxMs) return resolve();
                setTimeout(check, 200);
            })();
        });
    }

    const tick = setInterval(async () => {
        remaining--;
        if (remaining <= 0) {
            clearInterval(tick);
            timer.textContent = '0s';
            await flushAnswers(5000);
            window.location = questionsUrl;
            return;
        }
        timer.textContent = remaining + 's';
        if (remaining <= 30) timer.classList.add('warning');
    }, 1000);

    document.querySelectorAll('.question').forEach(row => {
        const qid = row.dataset.qid;
        row.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                row.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                row.classList.add('unsaved');
                pending.set(qid, btn.dataset.answer);
                send(qid);
            });
        });
    });

    submitForm.addEventListener('submit', async (event) => {
        if (pending.size === 0) return;
        event.preventDefault();
        submitForm.querySelector('button').disabled = true;
        await flushAnswers(8000);
        submitForm.submit();
    });
</script>
@endpush
@endsection
