@extends('student-test.layout')
@section('title', 'Hinweise')
@section('content')
    <div class="card">
        <h1>Hinweise zum Test</h1>
        <p style="white-space:pre-wrap;">{{ $noticeText }}</p>
        <p>
            <span class="label">Zeit:</span> <span class="badge">{{ $timeLimitSeconds }} Sekunden</span>
            @if($practiceSeconds > 0)
                <span class="label" style="margin-left:1rem;">Übung vorab:</span>
                <span class="badge">{{ $practiceSeconds }} Sek</span>
            @endif
        </p>

        <form method="GET" action="{{ route('student-test.questions') }}">
            <button class="btn btn-block" type="submit">Test starten</button>
        </form>
    </div>
@endsection
