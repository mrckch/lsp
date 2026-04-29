<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->string('label', 20)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('learning_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->text('description')->nullable();
            $table->enum('group_type', ['klasse', 'kurs'])->default('klasse');
            $table->string('grade_level', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['school_year_id', 'name', 'group_type']);
            $table->index(['school_year_id', 'grade_level']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('external_student_id', 50)->nullable();
            $table->string('external_id_source', 20)->default('manual');
            $table->string('student_code', 20)->unique();
            $table->binary('first_name_encrypted');
            $table->binary('last_name_encrypted');
            $table->enum('gender', ['m', 'w', 'd', 'unbekannt'])->default('unbekannt');
            $table->enum('status', ['aktiv', 'archiviert'])->default('aktiv');
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['external_student_id', 'external_id_source'], 'uq_students_external');
            $table->index('status');
        });

        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->string('grade_level', 10)->nullable();
            $table->boolean('is_repeater')->default(false);
            $table->date('enrolled_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'school_year_id']);
            $table->index(['school_year_id', 'grade_level']);
        });

        Schema::create('student_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'learning_group_id']);
            $table->index(['learning_group_id', 'school_year_id']);
        });

        // Nachträglicher FK für user_scope_assignments → learning_groups
        Schema::table('user_scope_assignments', function (Blueprint $table) {
            $table->foreign('learning_group_id')
                ->references('id')->on('learning_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_scope_assignments', function (Blueprint $table) {
            $table->dropForeign(['learning_group_id']);
        });
        Schema::dropIfExists('student_group_memberships');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('learning_groups');
        Schema::dropIfExists('school_years');
    }
};
