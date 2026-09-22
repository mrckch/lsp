<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->enum('status', ['uploaded', 'validated', 'diff_ready', 'committing', 'committed', 'aborted', 'failed'])
                ->default('uploaded')->change();
            $table->unsignedInteger('total_count')->nullable()->after('stats');
            $table->unsignedInteger('processed_count')->default(0)->after('total_count');
        });

        Schema::table('import_diff_entries', function (Blueprint $table) {
            $table->timestamp('committed_at')->nullable()->after('admin_decision_reason');
            $table->string('commit_outcome', 20)->nullable()->after('committed_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn(['total_count', 'processed_count']);
            $table->enum('status', ['uploaded', 'validated', 'diff_ready', 'committed', 'aborted', 'failed'])
                ->default('uploaded')->change();
        });

        Schema::table('import_diff_entries', function (Blueprint $table) {
            $table->dropColumn(['committed_at', 'commit_outcome']);
        });
    }
};
