<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('bet_slip')->unique();
            $table->enum('bet_type', ['2D', '3D']);
            $table->unsignedBigInteger('amount');
            $table->enum('status', ['PENDING', 'ACCEPTED', 'REJECTED', 'REFUNDED'])->default('PENDING');
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
