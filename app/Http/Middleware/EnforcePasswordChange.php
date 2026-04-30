<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wenn der eingeloggte User must_change_password=true hat, leitet Filament-Seiten
 * (außer der Force-Change-Page selbst und Logout) auf die Force-Change-Page um.
 */
class EnforcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        // Erlaubte Pfade: die Change-Page selbst und der Logout
        if ($request->is('admin/force-password-change') || $request->is('admin/logout')) {
            return $next($request);
        }

        return redirect('/admin/force-password-change');
    }
}
