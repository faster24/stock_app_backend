<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_odd_adjustments', function (Blueprint $table) {
            $table->id();
            $table->enum('bet_type', ['2D', '3D']);
            $table->enum('currency', ['MMK', 'THB']);
            $table->unsignedInteger('number');
            $table->string('target_opentime', 8);
            $table->date('stock_date');
            $table->decimal('base_odd', 10, 2);
            $table->decimal('adjusted_odd', 10, 2);
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['bet_type', 'currency', 'number', 'target_opentime', 'stock_date'],
                'uq_temp_odds'
            );
            $table->index(['stock_date', 'target_opentime'], 'idx_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_odd_adjustments');
    }
};
