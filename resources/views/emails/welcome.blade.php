<!DOCTYPE html>
<html>
<body style="font-family:Helvetica,Arial,sans-serif; color:#111; line-height:1.5;">
    <p>Hallo {{ $user->display_name }},</p>

    <p>für Sie wurde ein Zugang im <strong>Lese-Screening-Portal der {{ $schoolName }}</strong> angelegt.</p>

    <p>
        <strong>Login:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
        <strong>Benutzername:</strong> <code>{{ $user->username }}</code><br>
        <strong>Initial-Passwort:</strong>
        <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px;">{{ $initialPassword }}</code>
    </p>

    <p>Bei der ersten Anmeldung werden Sie aufgefordert, ein eigenes Passwort zu setzen.
       Anschließend empfehlen wir die Aktivierung der 2-Faktor-Authentifizierung
       in den Account-Einstellungen.</p>

    <p style="color:#6b7280; font-size:0.9rem;">
        Diese E-Mail enthält Zugangsdaten. Bitte umgehend löschen, sobald Sie sich erfolgreich
        angemeldet und ein eigenes Passwort gesetzt haben.
    </p>
</body>
</html>
