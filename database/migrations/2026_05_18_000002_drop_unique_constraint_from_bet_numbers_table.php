<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bet_numbers', function (Blueprint $table) {
            // FK must be dropped before the unique index it depends on
            $table->dropForeign(['bet_id']);
            $table->dropUnique(['bet_id', 'number']);
            // Plain index on bet_id keeps FK lookups fast
            $table->index('bet_id');
            $table->foreign('bet_id')->references('id')->on('bets')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('bet_numbers', function (Blueprint $table) {
            $table->dropForeign(['bet_id']);
            $table->dropIndex(['bet_id']);
            $table->unique(['bet_id', 'number']);
            $table->foreign('bet_id')->references('id')->on('bets')->onUpdate('cascade')->onDelete('cascade');
        });
    }
};
