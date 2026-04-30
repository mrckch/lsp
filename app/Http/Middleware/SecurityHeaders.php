<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security-Header für alle Antworten.
 *
 * - Content-Security-Policy: Filament + Schüler-Test brauchen Inline-Styles
 *   und (für Filament) wenige eval-ähnliche Operationen via Livewire/Alpine.
 *   Wir lassen self + 'unsafe-inline' für styles und 'unsafe-eval' für scripts
 *   zu — striktere CSP würde viele Filament-Komponenten brechen.
 * - X-Content-Type-Options: nosniff
 * - X-Frame-Options: SAMEORIGIN (Schutz gegen Clickjacking)
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Permissions-Policy: deaktiviert Geolocation/Mikrofon/Kamera (LSP braucht sie nicht)
 *
 * Nicht gesetzt: HSTS — das gehört auf den Reverse-Proxy (Caddy) wegen
 * korrekter TLS-Termination.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."font-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-ancestors 'self'; "
            ."base-uri 'self'; "
            ."form-action 'self'";

        $response->headers->set('Content-Security-Policy', $csp, false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()', false);

        return $response;
    }
}
