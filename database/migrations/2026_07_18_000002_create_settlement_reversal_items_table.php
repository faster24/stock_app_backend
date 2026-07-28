<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_reversal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_reversal_id')->constrained('settlement_reversals')->cascadeOnDelete();
            $table->uuid('bet_id')->index();
            $table->uuid('user_id')->index();
            $table->string('previous_result_status', 16);
            $table->unsignedBigInteger('paid_amount');
            $table->unsignedBigInteger('debited_amount');
            $table->unsignedBigInteger('shortfall_amount');
            $table->uuid('wallet_transaction_id')->nullable();
            $table->timestamp('shortfall_resolved_at')->nullable();
            $table->uuid('shortfall_resolved_by_user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['settlement_reversal_id', 'bet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_reversal_items');
    }
};
