<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'bets_user_id_stock_date_index';

    public function up(): void
    {
        if (! Schema::hasTable('bets')) {
            return;
        }

        Schema::table('bets', function (Blueprint $table): void {
            if (! Schema::hasColumn('bets', 'agent_commission_rate')) {
                $table->decimal('agent_commission_rate', 5, 2)->nullable()->after('total_amount');
            }

            if (! Schema::hasColumn('bets', 'agent_commission')) {
                $table->decimal('agent_commission', 20, 2)->nullable()->after('agent_commission_rate');
            }
        });

        // The commission report filters on exactly this pair.
        try {
            Schema::table('bets', function (Blueprint $table): void {
                $table->index(['user_id', 'stock_date'], self::INDEX);
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bets')) {
            return;
        }

        try {
            Schema::table('bets', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        } catch (\Throwable) {
        }

        Schema::table('bets', function (Blueprint $table): void {
            foreach (['agent_commission', 'agent_commission_rate'] as $column) {
                if (Schema::hasColumn('bets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
