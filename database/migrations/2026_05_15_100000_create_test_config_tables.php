<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('questionnaires', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('parallel_form', 10)->nullable();
            $table->string('grade_level_target', 20)->nullable();
            $table->unsignedInteger('default_time_limit_seconds')->default(180);
            $table->unsignedInteger('practice_time_seconds')->default(30);
            $table->enum('status', ['entwurf', 'aktiv', 'archiviert'])->default('entwurf');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('questionnaire_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->text('question_text');
            $table->enum('correct_answer', ['richtig', 'falsch']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['questionnaire_id', 'sort_order']);
        });

        Schema::create('questionnaire_practice_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->text('question_text');
            $table->enum('correct_answer', ['richtig', 'falsch']);
            $table->timestamps();
            // Expliziter, kürzerer Index-Name (MariaDB-Limit 64 Zeichen)
            $table->unique(['questionnaire_id', 'sort_order'], 'qpq_qid_sort_unique');
        });

        Schema::create('norm_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('version_label', 50)->nullable();
            $table->string('grade_level', 10);
            $table->string('parallel_form', 10);
            $table->enum('source_type', ['csv', 'xlsx', 'manuell'])->default('manuell');
            $table->enum('status', ['entwurf', 'aktiv', 'archiviert'])->default('aktiv');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
            $table->index(['grade_level', 'parallel_form', 'is_active']);
        });

        Schema::create('norm_table_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('norm_table_id')->constrained()->cascadeOnDelete();
            $table->integer('raw_score');
            $table->integer('quotient_female');
            $table->integer('quotient_male');
            $table->integer('quotient_diverse')->nullable();
            $table->unique(['norm_table_id', 'raw_score']);
        });

        Schema::create('feedback_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('status', ['entwurf', 'aktiv', 'archiviert'])->default('aktiv');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('feedback_set_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_set_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->string('name', 100);
            $table->enum('match_type', ['punkte', 'lq'])->default('lq');
            $table->integer('min_value');
            $table->integer('max_value');
            $table->longText('template_html');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notice_texts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('content');
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['entwurf', 'aktiv', 'archiviert'])->default('aktiv');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_texts');
        Schema::dropIfExists('feedback_set_ranges');
        Schema::dropIfExists('feedback_sets');
        Schema::dropIfExists('norm_table_rows');
        Schema::dropIfExists('norm_tables');
        Schema::dropIfExists('questionnaire_practice_questions');
        Schema::dropIfExists('questionnaire_questions');
        Schema::dropIfExists('questionnaires');
        Schema::dropIfExists('assessment_types');
    }
};
