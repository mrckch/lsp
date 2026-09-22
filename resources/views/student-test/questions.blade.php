@extends('student-test.layout')
@section('title', 'Aufgaben')
@section('html_class', 'focus')
@section('content')
    @include('student-test.partials.focus-bar', ['total' => $questions->count(), 'remaining' => $remaining])

    <div class="q-spacer" aria-hidden="true"></div>

    @foreach($questions as $i => $q)
        @include('student-test.partials.focus-card', [
            'q' => $q, 'index' => $i, 'total' => $questions->count(),
            'given' => $answers[$q->id] ?? null,
        ])
    @endforeach

    <section class="q-card q-final">
        <h2>Fertig?</h2>
        <p class="label" id="final-open"></p>
        <form method="POST" action="{{ route('student-test.submit') }}" id="submit-form">
            @csrf
            <button class="btn btn-block" type="submit">Test abgeben</button>
        </form>
    </section>

    <div class="q-spacer" aria-hidden="true"></div>

@push('scripts')
    @include('student-test.partials.focus-script', ['mode' => 'test', 'remaining' => $remaining])
@endpush
@endsection
