<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-gesäte Default-Druckvorlagen haben keinen menschlichen Ersteller.
 * created_by_user_id wird nullable, damit der Seeder bei Erstinstallation
 * (vor dem Setup-Wizard, ohne User) die Basis-Vorlagen anlegen kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_template_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('print_template_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable(false)->change();
        });
    }
};
