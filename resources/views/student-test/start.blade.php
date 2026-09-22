@extends('student-test.layout')
@section('title', 'Lese-Test – Anmeldung')
@section('content')
    <div class="card" style="margin-top:2rem;">
        <h1>Willkommen zum Lese-Test</h1>
        @if($error)<div class="err">{{ $error }}</div>@endif
        <p>Gib deinen 10-stelligen Zugangscode ein. Du findest ihn auf deiner Karte – oder scanne einfach den QR-Code auf der Karte.</p>
        <form method="POST" action="{{ route('student-test.login') }}" autocomplete="off">
            @csrf
            <input type="text" name="login_code" maxlength="10" minlength="10" required value="{{ $code }}" autofocus
                   autocapitalize="characters" spellcheck="false" aria-label="Zugangscode">
            <p style="margin-top:1rem;">
                <button class="btn btn-block" type="submit">Anmelden</button>
            </p>
        </form>
    </div>
    <p class="footer-link">
        Lehrkräfte &amp; Verwaltung: <a href="{{ url('/admin/login') }}">hier anmelden →</a>
    </p>
    @if(strlen($code) === 10 && ! $error)
        {{-- Per QR-Code aufgerufen: direkt anmelden, ohne Tippen (ideal am iPad). --}}
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelector('form').submit();
            });
        </script>
    @endif
@endsection
