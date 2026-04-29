<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('force_two_factor')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'user_group_id']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->string('area', 60)->index();
            $table->string('description', 255);
            $table->boolean('is_scopeable')->default(false);
            $table->boolean('requires_two_factor')->default(false);
            $table->timestamps();
        });

        Schema::create('group_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_group_id', 'permission_id']);
        });

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->enum('mode', ['grant', 'revoke']);
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });

        // Scope-Zuordnung kommt in Phase 1, sobald learning_groups existieren.
        // Wir legen die Tabelle bereits hier an, der FK wird nachgereicht.
        Schema::create('user_scope_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('learning_group_id')->index();
            $table->timestamps();
            $table->unique(['user_id', 'learning_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_scope_assignments');
        Schema::dropIfExists('user_permission_overrides');
        Schema::dropIfExists('group_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('user_group_memberships');
        Schema::dropIfExists('user_groups');
    }
};
