<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRecentTwoFactor;
use App\Http\Middleware\RequireSetupCompleted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'two_factor' => EnsureRecentTwoFactor::class,
        ]);

        // Auf jedem Web-Request prüfen, ob Setup gelaufen ist
        $middleware->appendToGroup('web', RequireSetupCompleted::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
