<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bets') || Schema::hasColumn('bets', 'currency')) {
            return;
        }

        Schema::table('bets', function (Blueprint $table) {
            $table->enum('currency', ['MMK', 'THB'])->default('MMK')->after('bet_type');
            $table->index('currency', 'bets_currency_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bets') || ! Schema::hasColumn('bets', 'currency')) {
            return;
        }

        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex('bets_currency_index');
            $table->dropColumn('currency');
        });
    }
};
