@extends('student-test.layout')
@section('title', 'Ergebnis')
@section('content')
    <div class="card">
        <h1>Test abgeschlossen</h1>
        @if($showScore)
            <p>Du hast den Test abgeschlossen.</p>
            <p style="font-size:1.25rem;">
                Richtige Antworten: <strong>{{ $attempt->score_raw }}</strong><br>
                Lesequotient (LQ): <strong>{{ $attempt->lq_current }}</strong>
            </p>
        @else
            <p>Vielen Dank! Dein Test wurde gespeichert. Deine Lehrkraft erhält die Auswertung.</p>
        @endif
        <p class="label">Du kannst diese Seite nun schließen.</p>
    </div>
@endsection
