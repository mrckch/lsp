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

// Schüler-Test (öffentlich, Code-basiert).
// Rate-Limits (siehe AppServiceProvider + config lsp.rate_limits) schützen vor
// Brute-Force auf Login-Codes, ohne ganze Klassen hinter einer Schul-NAT-IP auszusperren.
Route::prefix('t')->name('student-test.')->group(function () {
    Route::get('/', [StudentTestController::class, 'start'])->name('start');
    Route::post('/login', [StudentTestController::class, 'login'])
        ->middleware('throttle:student-login') // pro Code + großzügig pro IP
        ->name('login');
    Route::get('/hinweise', [StudentTestController::class, 'instructions'])->name('instructions');
    Route::get('/uebung', [StudentTestController::class, 'practice'])->name('practice');
    Route::post('/starten', [StudentTestController::class, 'begin'])->name('begin');
    Route::get('/aufgaben', [StudentTestController::class, 'questions'])->name('questions');
    // AJAX-Antworten: typisch ~30/Minute pro Schüler → Limit pro laufendem Versuch
    Route::post('/antwort', [StudentTestController::class, 'answer'])
        ->middleware('throttle:student-answer')
        ->name('answer');
    Route::post('/abgeben', [StudentTestController::class, 'submit'])->name('submit');
    Route::get('/ergebnis', [StudentTestController::class, 'result'])->name('result');
});

// Startseite = Code-Anmeldung für Schüler/innen. Lehrkräfte und Verwaltung
// melden sich unter /admin an (Link auf der Startseite).
Route::get('/', [StudentTestController::class, 'start'])->name('home');
