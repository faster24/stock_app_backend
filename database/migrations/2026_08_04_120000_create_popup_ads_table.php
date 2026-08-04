<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popup_ads', function (Blueprint $table) {
            // UUID rather than the auto-increment used by the other admin-config
            // tables: media.model_id is char(36), so every media-bearing model is
            // UUID-keyed and this one carries the ad artwork.
            $table->uuid('id')->primary();

            // Admin-facing label for the dashboard list. Never shown to players —
            // the artwork carries the message.
            $table->string('title');

            // Optional tap-through target. Null means the ad is not clickable.
            $table->string('link_url')->nullable();

            // Off by default so an ad cannot reach players before its image is uploaded.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popup_ads');
    }
};
