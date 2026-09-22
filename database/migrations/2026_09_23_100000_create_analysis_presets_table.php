<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gespeicherte Datenanalyse-Einstellungen (Filter + Ansicht), eigene oder für alle freigegebene
        Schema::create('analysis_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('settings');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
            $table->index(['is_shared', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_presets');
    }
};
