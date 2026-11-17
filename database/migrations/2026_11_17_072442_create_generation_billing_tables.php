<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->bigInteger('balance_nanos')->default(0);
            $table->bigInteger('reserved_nanos')->default(0);
        });
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 10);
            $table->json('reference_images');
            $table->json('quoted_pricing');
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->bigInteger('estimated_nanos');
            $table->bigInteger('reserved_nanos');
            $table->bigInteger('provider_nanos')->nullable();
            $table->bigInteger('actual_nanos')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('generation_id')->nullable()->unique();
            $table->foreign('generation_id')->references('id')->on('generations')->restrictOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('kind', 20);
            $table->bigInteger('amount_nanos');
            $table->timestamps();
        });
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('generations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'balance_nanos', 'reserved_nanos']));
    }
};
