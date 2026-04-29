<?php

declare(strict_types=1);

use App\Http\Controllers\SetupController;
use App\Http\Controllers\StudentTestController;
use Illuminate\Support\Facades\Route;

// Setup-Wizard (vor Login erreichbar)
Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup', [SetupController::class, 'process'])->name('setup.process');
Route::get('/setup/recovery', [SetupController::class, 'recovery'])->name('setup.recovery');
Route::post('/setup/recovery', [SetupController::class, 'recoveryAck'])->name('setup.recovery.ack');

// Schüler-Test (öffentlich, Code-basiert)
Route::prefix('t')->name('student-test.')->group(function () {
    Route::get('/', [StudentTestController::class, 'start'])->name('start');
    Route::post('/login', [StudentTestController::class, 'login'])->name('login');
    Route::get('/hinweise', [StudentTestController::class, 'instructions'])->name('instructions');
    Route::get('/aufgaben', [StudentTestController::class, 'questions'])->name('questions');
    Route::post('/antwort', [StudentTestController::class, 'answer'])->name('answer');
    Route::post('/abgeben', [StudentTestController::class, 'submit'])->name('submit');
    Route::get('/ergebnis', [StudentTestController::class, 'result'])->name('result');
});

// Default-Redirect
Route::get('/', function () {
    return redirect('/admin');
});
