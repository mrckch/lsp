<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wenn der eingeloggte User Mitglied einer UserGroup mit force_two_factor=true ist
 * UND noch kein 2FA aktiviert hat, leitet alle Filament-Pfade (außer der
 * Force-2FA-Setup-Page selbst, dem Logout und der Force-Password-Change-Page,
 * damit der Pflicht-Wechsel nicht blockiert wird) auf die 2FA-Setup-Pflicht-Seite um.
 *
 * Reihenfolge im Middleware-Stack: NACH EnforcePasswordChange — wenn beides
 * gleichzeitig fällig ist, kommt zuerst der Passwortwechsel, dann das 2FA-Setup.
 */
class EnforceTwoFactorIfRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->two_factor_enabled) {
            return $next($request);
        }

        $required = $user->userGroups()->where('force_two_factor', true)->exists();
        if (! $required) {
            return $next($request);
        }

        // Erlaubt: Force-Setup-Page, Force-Password-Change (Vorbedingung), Logout
        if (
            $request->is('admin/force-two-factor-setup')
            || $request->is('admin/force-password-change')
            || $request->is('admin/logout')
        ) {
            return $next($request);
        }

        return redirect('/admin/force-two-factor-setup');
    }
}
