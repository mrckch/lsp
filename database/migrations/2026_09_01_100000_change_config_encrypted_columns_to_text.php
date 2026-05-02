<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Änderung: config_encrypted-Spalten auf TEXT statt JSON.
 *
 * Hintergrund: Laravels 'encrypted:array'-Cast (verwendet in
 * ImportSource und BackupTarget) schreibt einen base64-Cipher-String.
 * Der ist KEIN valides JSON, daher kickt MariaDBs implizite
 * JSON_VALID-CHECK-Constraint mit SQLSTATE 23000 (Code 4025).
 * SQLite (Tests) hat keine JSON-Validierung — daher fiel es nur
 * in Production auf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sources', function (Blueprint $table) {
            $table->text('config_encrypted')->nullable()->change();
        });

        Schema::table('backup_targets', function (Blueprint $table) {
            $table->text('config_encrypted')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('import_sources', function (Blueprint $table) {
            $table->json('config_encrypted')->nullable()->change();
        });

        Schema::table('backup_targets', function (Blueprint $table) {
            $table->json('config_encrypted')->nullable()->change();
        });
    }
};
