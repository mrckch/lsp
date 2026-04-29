@extends('student-test.layout')
@section('title', 'Aufgaben')
@section('content')
    <div class="timer" id="timer">{{ $remaining }}s</div>
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
    <form method="POST" action="{{ route('student-test.submit') }}">
        @csrf
        <button class="btn btn-block" type="submit">Test abgeben</button>
    </form>

@push('scripts')
<script>
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let remaining = {{ $remaining }};
    const timer = document.getElementById('timer');

    const tick = setInterval(() => {
        remaining--;
        if (remaining <= 0) {
            clearInterval(tick);
            window.location = "{{ route('student-test.questions') }}";
            return;
        }
        timer.textContent = remaining + 's';
        if (remaining <= 30) timer.classList.add('warning');
    }, 1000);

    document.querySelectorAll('.question').forEach(row => {
        const qid = row.dataset.qid;
        row.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', async () => {
                row.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                await fetch("{{ route('student-test.answer') }}", {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ question_id: parseInt(qid, 10), answer: btn.dataset.answer })
                });
            });
        });
    });
</script>
@endpush
@endsection
