<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreignId('admin_bank_setting_id')->constrained('admin_bank_settings')->restrictOnDelete();
            $table->string('currency', 8);
            $table->unsignedBigInteger('claimed_amount');
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->string('transfer_note')->nullable();
            $table->string('status', 16);
            $table->text('admin_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
