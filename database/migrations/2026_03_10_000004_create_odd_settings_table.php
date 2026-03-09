<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odd_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('bet_type', ['STRAIGHT', 'PERMUTATION'])->unique();
            $table->decimal('odd', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odd_settings');
    }
};
