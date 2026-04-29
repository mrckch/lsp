<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wenn Setup noch nicht durchgeführt: leitet auf /setup um.
 */
class RequireSetupCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup', 'setup/*', 'health', 'up')) {
            return $next($request);
        }

        if (! AppSetting::isInitialized()) {
            return redirect('/setup');
        }

        return $next($request);
    }
}
