<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Singleton-Tabelle für Anwendungseinstellungen (Setup-Status, Schulname, …)
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('school_name')->nullable();
            $table->string('school_short_name', 50)->nullable();
            $table->boolean('is_initialized')->default(false);
            $table->timestamp('initialized_at')->nullable();
            $table->json('options')->nullable();          // freie Schlüssel/Werte
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
