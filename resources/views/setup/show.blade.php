@extends('layouts.setup')

@section('title', 'Erstinstallation')

@section('content')
    <h2 style="margin-top:0;">Willkommen bei der Erstinstallation</h2>
    <p class="help">
        Bitte legen Sie das Admin-Konto, den Schulnamen und das schulweite Klarnamen-Passwort fest.
        Dieser Vorgang findet einmalig statt. Im Anschluss erhalten Sie Ihren <strong>Recovery-Key</strong>,
        den Sie sicher verwahren müssen.
    </p>

    <form method="POST" action="{{ route('setup.process') }}" autocomplete="off">
        @csrf

        <h2>Schule</h2>
        <div class="field">
            <label for="school_name">Name der Schule *</label>
            <input id="school_name" name="school_name" type="text" required value="{{ old('school_name') }}" maxlength="255">
        </div>
        <div class="field">
            <label for="school_short_name">Kürzel (optional)</label>
            <input id="school_short_name" name="school_short_name" type="text" value="{{ old('school_short_name') }}" maxlength="50" placeholder="z. B. GYM-MS">
            <div class="help">Wird in Codes und Druckvorlagen verwendet.</div>
        </div>

        <h2>Admin-Konto</h2>
        <div class="field">
            <label for="admin_username">Benutzername *</label>
            <input id="admin_username" name="admin_username" type="text" required value="{{ old('admin_username') }}" pattern="[a-zA-Z0-9._\-]+" maxlength="100">
            <div class="help">Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich.</div>
        </div>
        <div class="field">
            <label for="admin_display_name">Anzeigename *</label>
            <input id="admin_display_name" name="admin_display_name" type="text" required value="{{ old('admin_display_name') }}" maxlength="255">
        </div>
        <div class="field">
            <label for="admin_email">E-Mail (optional)</label>
            <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" maxlength="255">
        </div>
        <div class="field">
            <label for="admin_password">Admin-Passwort *</label>
            <input id="admin_password" name="admin_password" type="password" required minlength="12" autocomplete="new-password">
            <div class="help">Mindestens 12 Zeichen.</div>
        </div>
        <div class="field">
            <label for="admin_password_confirmation">Passwort wiederholen *</label>
            <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required minlength="12" autocomplete="new-password">
        </div>

        <h2>Klarnamen-Passwort</h2>
        <div class="alert alert-warn">
            Mit diesem Passwort werden die <strong>Klarnamen der Schülerinnen und Schüler</strong> entsperrt.
            Es ist <em>nicht</em> identisch mit Ihrem Admin-Login. Sie können es jederzeit wechseln.
            Wenn alle Passwörter verloren gehen, ist nur über den Recovery-Key (nächster Schritt) eine Wiederherstellung möglich.
        </div>
        <div class="field">
            <label for="clearname_password">Klarnamen-Passwort *</label>
            <input id="clearname_password" name="clearname_password" type="password" required minlength="12" autocomplete="new-password">
        </div>
        <div class="field">
            <label for="clearname_password_confirmation">Klarnamen-Passwort wiederholen *</label>
            <input id="clearname_password_confirmation" name="clearname_password_confirmation" type="password" required minlength="12" autocomplete="new-password">
        </div>

        <div class="field">
            <label class="checkbox-row">
                <input type="checkbox" name="understand_recovery" required>
                <span>Ich habe verstanden, dass ich im nächsten Schritt einen <strong>Recovery-Key einmalig</strong>
                erhalte und diesen sicher verwahren muss. Ohne diesen Schlüssel und ohne ein gültiges
                Klarnamen-Passwort sind die verschlüsselten Schülerklarnamen unwiederbringlich verloren.</span>
            </label>
        </div>

        <button class="btn btn-block" type="submit">Einrichtung abschließen</button>
    </form>
@endsection
