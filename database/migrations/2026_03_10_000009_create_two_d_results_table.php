<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_d_results', function (Blueprint $table) {
            $table->id();
            $table->string('history_id')->unique();
            $table->date('stock_date')->nullable();
            $table->dateTime('stock_datetime')->nullable();
            $table->time('open_time')->nullable();
            $table->string('twod')->nullable();
            $table->string('set_index')->nullable();
            $table->string('value')->nullable();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_d_results');
    }
};
