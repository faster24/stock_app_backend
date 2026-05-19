<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('title');
            $table->text('body');
            $table->string('notification_type');
            $table->json('data')->nullable();
            $table->enum('status', ['sent', 'failed', 'delivered'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->index(['user_id', 'created_at']);
            $table->index(['notification_type']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
