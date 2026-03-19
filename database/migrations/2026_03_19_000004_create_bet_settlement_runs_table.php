<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_settlement_runs', function (Blueprint $table) {
            $table->id();
            $table->string('history_id')->unique();
            $table->foreignId('two_d_result_id')->nullable()->constrained('two_d_results')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_settlement_runs');
    }
};
