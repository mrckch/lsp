@extends('layouts.setup')

@section('title', 'Recovery-Key sichern')

@section('content')
    <h2 style="margin-top:0;">Recovery-Key sichern – einmalige Anzeige</h2>

    <div class="alert alert-danger">
        <strong>Wichtig:</strong> Dies ist die einzige Möglichkeit, diesen Recovery-Key anzuzeigen.
        Bewahren Sie ihn an einem <strong>sicheren Ort</strong> auf (Schultresor, verschlossener Schrank, Passwort-Manager der Schulleitung).
        Ohne diesen Schlüssel und ohne ein gültiges Klarnamen-Passwort sind die Schülerklarnamen <strong>unwiederbringlich verloren</strong>.
    </div>

    <div class="recovery-key">{{ $recoveryKey }}</div>

    <p class="help">
        Empfehlung: Drucken Sie diese Seite aus oder kopieren Sie den Schlüssel in einen verschlossenen Umschlag,
        beschriftet mit "LSP Recovery-Key – {{ now()->format('d.m.Y') }}".
    </p>

    <p>
        <button class="btn" onclick="window.print()" type="button">Diese Seite drucken</button>
    </p>

    <form method="POST" action="{{ route('setup.recovery.ack') }}" style="margin-top:2rem;">
        @csrf
        <div class="field">
            <label class="checkbox-row">
                <input type="checkbox" name="confirmed" required>
                <span>Ich bestätige, dass ich den Recovery-Key sicher verwahrt habe und mir bewusst ist,
                dass er <strong>nicht erneut anzeigbar</strong> ist.</span>
            </label>
        </div>
        <button class="btn" type="submit">Weiter zum Adminbereich</button>
    </form>
@endsection
