<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bet_id');
            $table->unsignedBigInteger('result_id');
            $table->enum('status', ['WON', 'LOST', 'VOID']);
            $table->unsignedBigInteger('payout_amount')->default(0);
            $table->timestamps();

            $table->foreign('bet_id')
                ->references('id')
                ->on('bets')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign('result_id')
                ->references('id')
                ->on('results')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->unique(['bet_id', 'result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_results');
    }
};
