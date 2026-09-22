<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Der Haupttest-Timer läuft ab `main_started_at` (Klick auf „Test starten“),
 * nicht mehr ab dem Login. Hinweise und Vorabübung kosten keine Testzeit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_attempts', function (Blueprint $table) {
            $table->timestamp('main_started_at')->nullable()->after('started_at');
        });

        // Bestandsversuche: bisherige Semantik (Timer ab Login) beibehalten
        DB::table('test_attempts')->whereNotNull('started_at')
            ->update(['main_started_at' => DB::raw('started_at')]);
    }

    public function down(): void
    {
        Schema::table('test_attempts', function (Blueprint $table) {
            $table->dropColumn('main_started_at');
        });
    }
};
