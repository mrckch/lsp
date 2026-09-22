@extends('student-test.layout')
@section('title', 'Übung')
@section('html_class', 'focus')
@section('content')
    @include('student-test.partials.focus-bar', [
        'total' => $questions->count(), 'remaining' => $remaining, 'timerLabel' => 'Übung',
    ])

    <div class="q-spacer" aria-hidden="true"></div>

    @foreach($questions as $i => $q)
        @include('student-test.partials.focus-card', [
            'q' => $q, 'index' => $i, 'total' => $questions->count(),
            'given' => null, 'correct' => $q->correct_answer,
        ])
    @endforeach

    <section class="q-card q-final">
        <h2>Übung beendet</h2>
        <p class="label" data-expired hidden>Die Übungszeit ist um.</p>
        <p>Jetzt beginnt der richtige Test. Die Zeit läuft ab dem Tipp auf „Test starten“.</p>
        <form method="POST" action="{{ route('student-test.begin') }}">
            @csrf
            <button class="btn btn-block" type="submit">Test starten</button>
        </form>
    </section>

    <div class="q-spacer" aria-hidden="true"></div>

@push('scripts')
    @include('student-test.partials.focus-script', ['mode' => 'practice', 'remaining' => $remaining])
@endpush
@endsection
