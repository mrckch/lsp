<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Permission\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsurePermission
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new HttpException(401, 'Nicht authentifiziert.');
        }

        if (! $this->resolver->can($user, $permissionKey)) {
            throw new HttpException(403, 'Fehlende Berechtigung: '.$permissionKey);
        }

        return $next($request);
    }
}
