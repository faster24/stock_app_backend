<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_controls', function (Blueprint $table) {
            $table->id();
            $table->enum('bet_type', ['2D', '3D']);
            $table->enum('currency', ['MMK', 'THB']);
            $table->unsignedInteger('number');
            // '' sentinel for 3D (no opentime); nullable would break the unique index
            $table->string('target_opentime', 8)->default('');
            $table->date('stock_date');
            $table->boolean('is_closed')->default(false);
            $table->decimal('sales_limit', 14, 2)->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['bet_type', 'currency', 'number', 'target_opentime', 'stock_date'],
                'uq_number_controls'
            );
            $table->index(['stock_date', 'target_opentime'], 'idx_number_controls_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_controls');
    }
};
