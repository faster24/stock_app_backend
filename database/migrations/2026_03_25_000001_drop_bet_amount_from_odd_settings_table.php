<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odd_settings') || ! Schema::hasColumn('odd_settings', 'bet_amount')) {
            return;
        }

        Schema::table('odd_settings', function (Blueprint $table) {
            $table->dropColumn('bet_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('odd_settings') || Schema::hasColumn('odd_settings', 'bet_amount')) {
            return;
        }

        Schema::table('odd_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('bet_amount')->default(0)->after('odd');
        });
    }
};
