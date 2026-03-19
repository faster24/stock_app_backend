<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->enum('bet_result_status', ['OPEN', 'WON', 'LOST', 'VOID'])->default('OPEN')->after('status');
            $table->enum('payout_status', ['PENDING', 'PAID_OUT'])->default('PENDING')->after('bet_result_status');
            $table->dateTime('settled_at')->nullable()->after('payout_status');
            $table->string('settled_result_history_id')->nullable()->after('settled_at');

            $table->index(
                ['status', 'stock_date', 'target_opentime', 'bet_result_status'],
                'bets_settlement_filter_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex('bets_settlement_filter_idx');
            $table->dropColumn([
                'bet_result_status',
                'payout_status',
                'settled_at',
                'settled_result_history_id',
            ]);
        });
    }
};
