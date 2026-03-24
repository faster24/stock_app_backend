<?php

use App\Enums\BetType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bet_settlement_runs', function (Blueprint $table) {
            $table->string('bet_type', 2)->nullable()->after('history_id');
            $table->foreignId('three_d_result_id')
                ->nullable()
                ->after('two_d_result_id')
                ->constrained('three_d_results')
                ->nullOnDelete();
        });

        DB::table('bet_settlement_runs')
            ->whereNull('bet_type')
            ->update([
                'bet_type' => BetType::TWO_D->value,
            ]);
    }

    public function down(): void
    {
        Schema::table('bet_settlement_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('three_d_result_id');
            $table->dropColumn('bet_type');
        });
    }
};
