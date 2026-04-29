<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eine aktive DEK; ältere Versionen für Re-Encryption-Vorgänge.
        Schema::create('encryption_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('key_version')->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('retired_at')->nullable();
        });

        // DEK gewrapped pro Schlüsselträger (User-Passwort oder Recovery-Key).
        Schema::create('key_wraps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encryption_key_id')->constrained()->cascadeOnDelete();
            $table->enum('wrap_type', ['user_password', 'recovery_key']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->binary('wrapped_dek');               // verschlüsselte DEK (AES-256-GCM Output)
            $table->binary('wrap_nonce');                // Nonce für AES-GCM
            $table->binary('wrap_tag');                  // GCM Auth-Tag
            $table->binary('kdf_salt');                  // Salt für Argon2id
            $table->json('kdf_params');                  // {memory_cost, time_cost, threads}
            $table->string('verification_hash', 255);    // SHA-256 der DEK, zur Verifikation nach Unwrap
            $table->timestamp('rotation_required_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'wrap_type']);
            $table->unique(['encryption_key_id', 'user_id', 'wrap_type'], 'uq_key_wraps_per_holder');
        });

        // Metadaten zum Recovery-Key (der Key selbst ist NICHT gespeichert).
        Schema::create('recovery_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encryption_key_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('fingerprint', 64);          // SHA-256 des Recovery-Keys (Identifikation)
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_keys');
        Schema::dropIfExists('key_wraps');
        Schema::dropIfExists('encryption_keys');
    }
};
