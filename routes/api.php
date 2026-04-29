<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return ['status' => 'ok', 'version' => config('app.version', '0.1.0-dev')];
    });
});
