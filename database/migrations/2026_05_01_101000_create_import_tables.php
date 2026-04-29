<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->string('type', 50);
            $table->json('config_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->enum('group_type', ['klasse', 'kurs'])->default('klasse');
            $table->string('filename', 255)->nullable();
            $table->enum('status', ['uploaded', 'validated', 'diff_ready', 'committed', 'aborted', 'failed'])->default('uploaded');
            $table->json('mapping')->nullable();
            $table->json('stats')->nullable();
            $table->foreignId('started_by_user_id')->constrained('users');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->text('error_message')->nullable();
        });

        Schema::create('import_diff_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('external_student_id', 50)->nullable();
            $table->enum('action', ['create', 'update', 'archive', 'skip', 'error']);
            $table->foreignId('matched_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->json('errors')->nullable();
            $table->enum('admin_decision', ['confirm', 'exclude'])->default('confirm');
            $table->string('admin_decision_reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['import_job_id', 'action']);
        });

        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename', 255);
            $table->string('source_key', 50);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_archived')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->foreignId('imported_by_user_id')->constrained('users');
            $table->timestamp('imported_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
        Schema::dropIfExists('import_diff_entries');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('import_sources');
    }
};
