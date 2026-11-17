<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->string('scope')->primary();
            $table->json('request_timestamps');
            $table->unsignedInteger('request_limit')->default(60);
            $table->unsignedInteger('remaining')->nullable();
            $table->timestamp('remaining_resets_at')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('daily_blocked_until')->nullable();
        });
        Schema::table('generations', function (Blueprint $table) {
            $table->string('provider_account')->default('primary');
            $table->boolean('provider_slot')->default(false);
            $table->timestamp('provider_created_at')->nullable();
            $table->unsignedInteger('submission_attempts')->default(0);
            $table->boolean('submission_uncertain')->default(false);
            $table->timestamp('submission_retry_at')->nullable();
            $table->timestamp('sync_retry_at')->nullable();
            $table->unsignedInteger('sync_failures')->default(0);
            $table->string('waiting_message')->nullable();
            $table->index(['provider_account', 'provider_slot']);
            $table->index(['provider_account', 'provider_created_at']);
        });
        DB::table('generations')->update(['provider_account' => config('services.pixelforge.account_scope')]);
        DB::table('generations')->whereNotNull('provider_id')->update(['provider_created_at' => DB::raw('created_at')]);
        DB::table('generations')->whereIn('status', ['queued', 'processing'])->whereNotNull('provider_id')
            ->update(['provider_slot' => true]);
        DB::table('generations')->whereIn('status', ['queued', 'processing'])->whereNull('provider_id')->whereNotNull('error_message')
            ->update(['provider_slot' => true, 'submission_uncertain' => true]);
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['provider_account', 'provider_slot']);
            $table->dropIndex(['provider_account', 'provider_created_at']);
            $table->dropColumn(['provider_account', 'provider_slot', 'provider_created_at', 'submission_attempts',
                'submission_uncertain', 'submission_retry_at', 'sync_retry_at', 'sync_failures', 'waiting_message']);
        });
        Schema::dropIfExists('provider_accounts');
    }
};
