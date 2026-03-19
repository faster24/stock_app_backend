<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->enum('target_opentime', ['11:00:00', '12:01:00', '15:00:00', '16:30:00'])
                ->nullable()
                ->after('bet_type');
            $table->date('stock_date')->nullable()->after('target_opentime');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropColumn(['target_opentime', 'stock_date']);
        });
    }
};
