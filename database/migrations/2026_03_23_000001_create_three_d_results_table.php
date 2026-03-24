<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('three_d_results', function (Blueprint $table) {
            $table->id();
            $table->date('stock_date')->unique();
            $table->string('threed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('three_d_results');
    }
};
