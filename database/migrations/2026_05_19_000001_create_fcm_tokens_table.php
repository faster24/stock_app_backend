<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('token')->unique();
            $table->enum('device_type', ['android', 'ios', 'web'])->default('web');
            $table->string('device_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
