<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dateTime('paid_out_at')->nullable()->after('payout_status');
            $table->unsignedBigInteger('paid_out_by_user_id')->nullable()->after('paid_out_at');
            $table->string('payout_reference')->nullable()->after('paid_out_by_user_id');
            $table->text('payout_note')->nullable()->after('payout_reference');

            $table->foreign('paid_out_by_user_id')
                ->references('id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropForeign(['paid_out_by_user_id']);
            $table->dropColumn([
                'paid_out_at',
                'paid_out_by_user_id',
                'payout_reference',
                'payout_note',
            ]);
        });
    }
};

