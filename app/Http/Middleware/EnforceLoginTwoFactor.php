<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Echtes Login-2FA: Wer 2FA aktiviert hat, muss nach der Passwort-Anmeldung
 * einen TOTP-Code bestätigen, bevor das Panel nutzbar ist. Der Nachweis gilt
 * pro Session (`login_2fa_ok`) und wird bei jedem neuen Login zurückgesetzt
 * (siehe App\Filament\Pages\Auth\Login).
 *
 * Reihenfolge: NACH EnforceTwoFactorIfRequired — wer 2FA erst noch einrichten
 * muss (force_two_factor-Gruppe, two_factor_enabled=false), wird dort zuerst
 * zur Einrichtung geleitet und ist hier noch nicht betroffen.
 */
class EnforceLoginTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->two_factor_enabled) {
            return $next($request);
        }

        if ($request->session()->get('login_2fa_ok') === true) {
            return $next($request);
        }

        // Challenge-Seite und Logout müssen erreichbar bleiben.
        if ($request->is('admin/login-two-factor') || $request->is('admin/logout')) {
            return $next($request);
        }

        return redirect('/admin/login-two-factor');
    }
}
