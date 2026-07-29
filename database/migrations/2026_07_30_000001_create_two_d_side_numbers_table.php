<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_d_side_numbers', function (Blueprint $table) {
            $table->id();
            $table->date('result_date');
            $table->string('slot');                       // App\Enums\TwoDSideSlot value

            // Strings, not integers: the upstream publishes zero-padded pairs and
            // "07" must not become 7. Nullable because HtayApi can publish one
            // side of the pair without the other.
            $table->string('modern', 2)->nullable();
            $table->string('internet', 2)->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // Idempotency / concurrency guarantee: one row per date + slot. The
            // left prefix also serves date range scans, so no second index.
            $table->unique(['result_date', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_d_side_numbers');
    }
};
