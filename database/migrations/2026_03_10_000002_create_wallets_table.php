<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->unique();
            $table->unsignedBigInteger('balance')->default(0);
            $table->string('currency', 8)->nullable();
            $table->timestamp('currency_locked_at')->nullable();
            $table->enum('bank_name', ['KBZ', 'AYA', 'CB', 'UAB', 'YOMA', 'OTHER'])->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->index('currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
