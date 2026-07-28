<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_session_results', function (Blueprint $table) {
            $table->id();
            $table->date('result_date');
            $table->string('session');            // App\Enums\SetSession value
            $table->string('two_d', 2)->nullable();
            $table->string('digit_one', 1)->nullable();
            $table->string('digit_two', 1)->nullable();
            $table->string('set_index_value')->nullable();   // raw string, exact
            $table->string('set_total_value')->nullable();   // raw string, exact
            $table->string('market_status')->nullable();
            $table->dateTime('market_datetime')->nullable();
            $table->boolean('stabilized')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // Idempotency / concurrency guarantee: one row per date + session.
            $table->unique(['result_date', 'session']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_session_results');
    }
};
