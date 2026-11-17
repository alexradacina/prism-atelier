<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->bigInteger('balance_after_nanos')->default(0);
            $table->index(['user_id', 'id']);
        });
        DB::table('users')->orderBy('id')->eachById(function ($user) {
            DB::transaction(function () use ($user) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                $balance = 0;
                foreach (DB::table('ledger_entries')->where('user_id', $user->id)->orderBy('id')->get() as $entry) {
                    $balance += (int) $entry->amount_nanos;
                    DB::table('ledger_entries')->where('id', $entry->id)->update(['balance_after_nanos' => $balance]);
                }
            });
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! Schema::hasIndex('ledger_entries', ['user_id'])) {
            Schema::table('ledger_entries', fn (Blueprint $table) => $table->index('user_id'));
        }
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'id']);
            $table->dropColumn('balance_after_nanos');
        });
    }
};
