@extends('student-test.layout')
@section('title', 'Lese-Test starten')
@section('content')
    <div class="card">
        <h1>Willkommen zum Lese-Test</h1>
        @if($error)<div class="err">{{ $error }}</div>@endif
        <p>Gib deinen 10-stelligen Zugangscode ein:</p>
        <form method="POST" action="{{ route('student-test.login') }}" autocomplete="off">
            @csrf
            <input type="text" name="login_code" maxlength="10" minlength="10" required value="{{ $code }}" autofocus>
            <p style="margin-top:1rem;">
                <button class="btn btn-block" type="submit">Anmelden</button>
            </p>
        </form>
    </div>
@endsection
