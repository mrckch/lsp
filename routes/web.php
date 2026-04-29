<?php

declare(strict_types=1);

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

// Setup-Wizard (vor Login erreichbar)
Route::get('/setup',           [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup',          [SetupController::class, 'process'])->name('setup.process');
Route::get('/setup/recovery',  [SetupController::class, 'recovery'])->name('setup.recovery');
Route::post('/setup/recovery', [SetupController::class, 'recoveryAck'])->name('setup.recovery.ack');

// Default-Redirect
Route::get('/', function () {
    return redirect('/admin');
});
