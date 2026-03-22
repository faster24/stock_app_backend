<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bets MODIFY payout_status ENUM('PENDING', 'PAID_OUT', 'REFUNDED') NOT NULL DEFAULT 'PENDING';");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bets MODIFY payout_status ENUM('PENDING', 'PAID_OUT') NOT NULL DEFAULT 'PENDING';");
    }
};
