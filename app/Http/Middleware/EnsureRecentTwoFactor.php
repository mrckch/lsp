<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stellt sicher, dass der User in den letzten X Minuten 2FA bestätigt hat.
 * TTL aus config('lsp.two_factor.reauth_ttl_minutes').
 */
class EnsureRecentTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new HttpException(401, 'Nicht authentifiziert.');
        }

        if (! $user->two_factor_enabled) {
            throw new HttpException(403, '2FA für diese Aktion erforderlich, ist aber nicht aktiviert.');
        }

        $ttl = (int) config('lsp.two_factor.reauth_ttl_minutes', 15);
        $last = $user->last_2fa_at;

        if ($last === null || $last->lt(now()->subMinutes($ttl))) {
            // Hinweis-Header für das Frontend, das daraufhin den 2FA-Re-Auth zeigt
            throw new HttpException(403, 'Aktuelle 2FA-Bestätigung erforderlich.', null, [
                'X-LSP-Reauth-Required' => '2fa',
            ]);
        }

        return $next($request);
    }
}
