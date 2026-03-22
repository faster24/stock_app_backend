<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bets')
            ->whereIn('status', ['REJECTED', 'REFUNDED'])
            ->where('bet_result_status', '!=', 'INVALID')
            ->update([
                'bet_result_status' => 'INVALID',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-reversible data normalization.
    }
};
