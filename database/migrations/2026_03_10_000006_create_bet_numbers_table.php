<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bet_id');
            $table->unsignedTinyInteger('number');
            $table->timestamps();

            $table->foreign('bet_id')
                ->references('id')
                ->on('bets')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->unique(['bet_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_numbers');
    }
};
