<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the SET-index scraper's table along with the scraper itself.
 *
 * The table never held a row in production: every scheduled `set:capture` run
 * failed on the Node scraper, and the `TWOD_DRIVER=set` provider that read this
 * table was never active. Nothing is lost.
 *
 * The 2026_07_26 create-migration is left in place so an existing database
 * replays its history in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('set_session_results');
    }

    /**
     * Recreates the table verbatim so the migration is reversible, even though
     * nothing writes to it any more — `rollback` must not leave a database the
     * earlier migration's `down()` cannot drop.
     */
    public function down(): void
    {
        Schema::create('set_session_results', function (Blueprint $table) {
            $table->id();
            $table->date('result_date');
            $table->string('session');
            $table->string('two_d', 2)->nullable();
            $table->string('digit_one', 1)->nullable();
            $table->string('digit_two', 1)->nullable();
            $table->string('set_index_value')->nullable();
            $table->string('set_total_value')->nullable();
            $table->string('market_status')->nullable();
            $table->dateTime('market_datetime')->nullable();
            $table->boolean('stabilized')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['result_date', 'session']);
        });
    }
};
