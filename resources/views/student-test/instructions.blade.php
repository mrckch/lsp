@extends('student-test.layout')
@section('title', 'Hinweise')
@section('content')
    <div class="card">
        <h1>Hinweise zum Test</h1>
        <p style="white-space:pre-wrap;">{{ $noticeText }}</p>
        <p>
            <span class="label">Zeit:</span> <span class="badge">{{ $timeLimitSeconds }} Sekunden</span>
            @if($hasPractice)
                <span class="label" style="margin-left:1rem;">Übung vorab:</span>
                <span class="badge">{{ $practiceSeconds }} Sek</span>
            @endif
        </p>
        <p class="label">Die Zeit läuft erst, wenn du auf „Test starten“ tippst.</p>

        @if($hasPractice)
            <p>Zuerst probierst du in einer kurzen Übung aus, wie es funktioniert. Die Übung zählt nicht.</p>
            <form method="GET" action="{{ route('student-test.practice') }}">
                <button class="btn btn-block" type="submit">Übung starten</button>
            </form>
        @else
            <form method="POST" action="{{ route('student-test.begin') }}">
                @csrf
                <button class="btn btn-block" type="submit">Test starten</button>
            </form>
        @endif
    </div>
@endsection
