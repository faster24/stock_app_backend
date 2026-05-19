<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnUpdate()->restrictOnDelete();
            $table->uuid('user_id');
            $table->string('type', 32);
            $table->string('direction', 8);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('currency', 8);
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
