<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_reversals', function (Blueprint $table) {
            $table->id();
            // Not unique: the same history can be reverted again after a re-settle cycle.
            $table->string('history_id')->index();
            $table->string('bet_type', 2);
            $table->foreignId('two_d_result_id')->nullable()->constrained('two_d_results')->nullOnDelete();
            $table->foreignId('three_d_result_id')->nullable()->constrained('three_d_results')->nullOnDelete();
            $table->unsignedSmallInteger('reverted_winning_number')->nullable();
            $table->uuid('reverted_by_user_id');
            $table->text('reason');
            $table->string('status', 16)->default('REVERTING');
            $table->timestamp('run_settled_at')->nullable();
            $table->json('original_summary')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('total_debited')->default(0);
            $table->unsignedBigInteger('total_shortfall')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_reversals');
    }
};
