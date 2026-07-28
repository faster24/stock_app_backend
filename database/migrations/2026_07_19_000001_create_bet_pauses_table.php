<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_pauses', function (Blueprint $table) {
            $table->id();
            $table->enum('bet_type', ['2D', '3D']);
            $table->boolean('is_enabled')->default(false);
            // Absolute instant the pause takes effect; blocks all bets of bet_type until disabled
            $table->timestamp('pause_from')->nullable();
            $table->string('message', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bet_type'], 'uq_bet_pauses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_pauses');
    }
};
