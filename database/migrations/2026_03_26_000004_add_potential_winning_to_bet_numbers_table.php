<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bet_numbers') || Schema::hasColumn('bet_numbers', 'potential_winning')) {
            return;
        }

        Schema::table('bet_numbers', function (Blueprint $table) {
            $table->decimal('potential_winning', 20, 2)->default(0)->after('amount');
        });

        DB::table('bet_numbers')->update([
            'potential_winning' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('bet_numbers') || ! Schema::hasColumn('bet_numbers', 'potential_winning')) {
            return;
        }

        Schema::table('bet_numbers', function (Blueprint $table) {
            $table->dropColumn('potential_winning');
        });
    }
};
