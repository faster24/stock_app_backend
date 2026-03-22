<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE bets SET bet_result_status = 'INVALID' WHERE bet_result_status = 'VOID';");
        DB::statement("ALTER TABLE bets MODIFY bet_result_status ENUM('OPEN', 'WON', 'LOST', 'INVALID') NOT NULL DEFAULT 'OPEN';");
    }

    public function down(): void
    {
        DB::statement("UPDATE bets SET bet_result_status = 'VOID' WHERE bet_result_status = 'INVALID';");
        DB::statement("ALTER TABLE bets MODIFY bet_result_status ENUM('OPEN', 'WON', 'LOST', 'VOID') NOT NULL DEFAULT 'OPEN';");
    }
};
