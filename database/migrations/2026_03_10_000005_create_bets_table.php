<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('round_id');
            $table->enum('bet_type', ['STRAIGHT', 'PERMUTATION']);
            $table->unsignedBigInteger('amount');
            $table->enum('status', ['PENDING', 'WON', 'LOST', 'CANCELLED'])->default('PENDING');
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign('round_id')
                ->references('id')
                ->on('rounds')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
