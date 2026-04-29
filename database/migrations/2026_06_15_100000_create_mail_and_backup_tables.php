<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('smtp_host', 255)->nullable();
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_username', 255)->nullable();
            $table->text('smtp_password_encrypted')->nullable();
            $table->enum('smtp_encryption', ['tls', 'starttls', 'none'])->default('tls');
            $table->string('from_address', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('reply_to', 255)->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->text('to_addresses');
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->string('subject', 500);
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed', 'bounced'])->default('queued');
            $table->text('error_message')->nullable();
            $table->string('related_entity_type', 50)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->boolean('includes_clearnames')->default(false);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'created_at']);
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('backup_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('type', ['local', 'sftp', 's3'])->default('local');
            $table->json('config_encrypted')->nullable();
            $table->text('encryption_password_encrypted')->nullable();
            $table->unsignedSmallInteger('retention_daily')->default(7);
            $table->unsignedSmallInteger('retention_weekly')->default(4);
            $table->unsignedSmallInteger('retention_monthly')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_target_id')->constrained()->cascadeOnDelete();
            $table->enum('trigger', ['manual', 'scheduled'])->default('manual');
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->boolean('includes_db')->default(true);
            $table->boolean('includes_files')->default(true);
            $table->boolean('includes_config')->default(true);
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['backup_target_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_targets');
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_settings');
    }
};
