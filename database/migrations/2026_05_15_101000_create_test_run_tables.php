<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('short_code', 20)->unique();
            $table->enum('status', ['in_vorbereitung', 'aktiv', 'pausiert', 'abgeschlossen', 'archiviert'])
                ->default('in_vorbereitung');
            $table->foreignId('questionnaire_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('norm_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feedback_set_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notice_text_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('time_limit_seconds')->default(180);
            $table->unsignedInteger('practice_time_seconds')->default(30);
            $table->boolean('show_score_to_student')->default(true);
            $table->boolean('allow_teacher_reset')->default(true);
            $table->date('scheduled_for')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_year_id', 'status']);
        });

        Schema::create('test_run_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_group_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['test_run_id', 'learning_group_id']);
        });

        Schema::create('test_run_security', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('teacher_access_code', 12)->nullable();
            $table->boolean('teacher_access_code_is_active')->default(false);
            $table->string('clearname_release_code_hash', 255)->nullable();
            $table->timestamp('clearname_code_generated_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('questionnaire_id')->nullable()->constrained()->nullOnDelete();
            $table->string('parallel_form', 10)->nullable();
            $table->foreignId('norm_table_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['gestartet', 'abgegeben', 'zeit_abgelaufen', 'abgebrochen', 'zurueckgesetzt'])
                ->default('gestartet');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('time_limit_seconds')->default(180);
            $table->unsignedInteger('score_raw')->default(0);
            $table->integer('lq_at_submission')->nullable();
            $table->integer('lq_current')->nullable();
            $table->timestamp('lq_calculated_at')->nullable();
            $table->enum('ended_by', ['schueler', 'system', 'admin', 'lehrkraft'])->nullable();
            $table->foreignId('reset_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reset_reason', 255)->nullable();
            $table->char('login_code_used', 10)->nullable();
            $table->timestamps();
            $table->index(['student_id', 'submitted_at']);
            $table->index(['test_run_id', 'status']);
        });

        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questionnaire_questions')->cascadeOnDelete();
            $table->enum('given_answer', ['richtig', 'falsch']);
            $table->boolean('is_correct');
            $table->timestamp('answered_at')->useCurrent();
            $table->unique(['test_attempt_id', 'question_id']);
        });

        Schema::create('attempt_lq_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('norm_table_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('lq_value')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->foreignId('calculated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255);
            $table->index(['test_attempt_id', 'calculated_at']);
        });

        Schema::create('student_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_run_id')->constrained()->cascadeOnDelete();
            $table->char('login_code', 10)->unique();
            $table->enum('status', ['aktiv', 'in_bearbeitung', 'verbraucht', 'gesperrt'])->default('aktiv');
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('reset_at')->nullable();
            $table->foreignId('reset_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'test_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_login_codes');
        Schema::dropIfExists('attempt_lq_history');
        Schema::dropIfExists('attempt_answers');
        Schema::dropIfExists('test_attempts');
        Schema::dropIfExists('test_run_security');
        Schema::dropIfExists('test_run_groups');
        Schema::dropIfExists('test_runs');
    }
};
