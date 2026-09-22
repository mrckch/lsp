<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-gesäte Default-Rückmeldesets haben keinen menschlichen Ersteller.
 * created_by_user_id wird daher nullable (der Seeder läuft bei Erstinstallation
 * vor dem Setup-Wizard, es existiert noch kein User).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_sets', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_sets', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable(false)->change();
        });
    }
};
