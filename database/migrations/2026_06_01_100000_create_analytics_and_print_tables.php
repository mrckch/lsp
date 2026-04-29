<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('metric', ['lq_absolute', 'lq_delta', 'lq_below_class_median']);
            $table->enum('operator', ['lt', 'le', 'gt', 'ge', 'eq']);
            $table->integer('value');
            $table->unsignedInteger('window_count')->nullable();
            $table->enum('severity', ['hinweis', 'auffaellig', 'foerderbedarf']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('type', 50);
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('print_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->longText('html_content');
            $table->longText('css_content')->nullable();
            $table->json('variables_schema')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['print_template_id', 'version_number']);
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('mime_type', 50)->default('application/pdf');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('includes_clearnames')->default(false);
            $table->char('sha256', 64);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['expires_at']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_template_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context_type', 50);
            $table->unsignedBigInteger('context_id')->nullable();
            $table->json('parameters')->nullable();
            $table->enum('status', ['pending', 'running', 'done', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->foreignId('output_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['status', 'requested_at']);
        });

        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->string('export_type', 50);
            $table->string('context_type', 50)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->foreignId('generated_document_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('includes_clearnames')->default(false);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('print_template_versions');
        Schema::dropIfExists('print_templates');
        Schema::dropIfExists('support_thresholds');
    }
};
