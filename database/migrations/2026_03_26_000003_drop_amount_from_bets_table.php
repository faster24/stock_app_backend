<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bets') || ! Schema::hasColumn('bets', 'amount')) {
            return;
        }

        Schema::table('bets', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bets') || Schema::hasColumn('bets', 'amount')) {
            return;
        }

        Schema::table('bets', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->default(0)->after('stock_date');
        });
    }
};
