<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digit_controls', function (Blueprint $table) {
            $table->id();
            // Mirrors number_controls so a 3D variant needs no schema change,
            // even though only 2D accepts a first-digit control today.
            $table->enum('bet_type', ['2D', '3D']);
            $table->enum('currency', ['MMK', 'THB']);
            // The FIRST digit of a 2D number, 0-9. A row's existence IS the
            // "hot" flag; un-hotting deletes it, as NumberControlService::reopen does.
            $table->unsignedTinyInteger('digit');
            // '' sentinel for 3D (no opentime); nullable would break the unique index
            $table->string('target_opentime', 8)->default('');
            $table->date('stock_date');
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['bet_type', 'currency', 'digit', 'target_opentime', 'stock_date'],
                'uq_digit_controls'
            );
            $table->index(['stock_date', 'target_opentime'], 'idx_digit_controls_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digit_controls');
    }
};
