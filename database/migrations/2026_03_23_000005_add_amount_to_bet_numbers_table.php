<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bet_numbers') || Schema::hasColumn('bet_numbers', 'amount')) {
            return;
        }

        Schema::table('bet_numbers', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->default(0)->after('number');
        });

        $betAmounts = DB::table('bets')
            ->pluck('amount', 'id')
            ->all();

        foreach (DB::table('bet_numbers')->select('id', 'bet_id')->get() as $betNumber) {
            $resolvedAmount = (int) ($betAmounts[$betNumber->bet_id] ?? 0);

            DB::table('bet_numbers')
                ->where('id', $betNumber->id)
                ->update(['amount' => $resolvedAmount]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bet_numbers') || ! Schema::hasColumn('bet_numbers', 'amount')) {
            return;
        }

        Schema::table('bet_numbers', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
