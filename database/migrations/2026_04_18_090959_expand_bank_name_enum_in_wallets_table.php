<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FULL = ['KBZ', 'AYA', 'CB', 'UAB', 'YOMA', 'SCB', 'KBANK', 'BBL', 'KTB', 'BAY', 'TTB', 'GSB', 'OTHER'];
    private const ORIGINAL = ['KBZ', 'AYA', 'CB', 'UAB', 'YOMA', 'OTHER'];

    public function up(): void
    {
        $values = implode("','", self::FULL);
        DB::statement("ALTER TABLE wallets MODIFY COLUMN bank_name ENUM('{$values}') NULL");
    }

    public function down(): void
    {
        $values = implode("','", self::ORIGINAL);
        DB::statement("ALTER TABLE wallets MODIFY COLUMN bank_name ENUM('{$values}') NULL");
    }
};
