<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('round_id')->unique();
            $table->unsignedTinyInteger('winning_number');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('round_id')
                ->references('id')
                ->on('rounds')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
