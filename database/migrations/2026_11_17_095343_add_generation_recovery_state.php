<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->timestamp('sync_paused_at')->nullable()->index();
            $table->unsignedSmallInteger('sync_error_status')->nullable();
            $table->json('submission_pricing')->nullable();
            $table->timestamp('pricing_checked_at')->nullable();
            $table->unsignedInteger('pricing_failures')->default(0);
        });
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
        });
        DB::table('webhook_events')->whereNotNull('processed_at')->update(['status' => 'processed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'processing_attempts', 'last_attempted_at', 'next_attempt_at', 'last_error']);
        });
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['sync_paused_at']);
            $table->dropColumn(['sync_paused_at', 'sync_error_status', 'submission_pricing', 'pricing_checked_at', 'pricing_failures']);
        });
    }
};
