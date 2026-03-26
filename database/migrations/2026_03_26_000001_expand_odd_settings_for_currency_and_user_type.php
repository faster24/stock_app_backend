<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odd_settings')) {
            return;
        }

        Schema::table('odd_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('odd_settings', 'currency')) {
                $table->enum('currency', ['MMK', 'THB'])->default('MMK')->after('bet_type');
            }

            if (! Schema::hasColumn('odd_settings', 'user_type')) {
                $table->enum('user_type', ['user', 'vip'])->default('user')->after('currency');
            }
        });

        DB::table('odd_settings')->whereNull('currency')->update(['currency' => 'MMK']);
        DB::table('odd_settings')->whereNull('user_type')->update(['user_type' => 'user']);

        $this->dropBetTypeUniqueIndex();
        $this->backfillCombinations();
        $this->ensureCompositeUniqueIndex();
    }

    public function down(): void
    {
        if (! Schema::hasTable('odd_settings')) {
            return;
        }

        $this->dropCompositeUniqueIndex();
        $this->collapseToSingleRowPerBetType();

        Schema::table('odd_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('odd_settings', 'user_type')) {
                $table->dropColumn('user_type');
            }

            if (Schema::hasColumn('odd_settings', 'currency')) {
                $table->dropColumn('currency');
            }
        });

        $this->ensureBetTypeUniqueIndex();
    }

    private function backfillCombinations(): void
    {
        $sourceRows = DB::table('odd_settings')
            ->select(['id', 'bet_type', 'odd', 'is_active'])
            ->orderBy('id')
            ->get()
            ->groupBy('bet_type')
            ->map(fn ($rows) => $rows->first());

        $currencies = ['MMK', 'THB'];
        $userTypes = ['user', 'vip'];

        foreach ($sourceRows as $betType => $source) {
            foreach ($currencies as $currency) {
                foreach ($userTypes as $userType) {
                    $exists = DB::table('odd_settings')
                        ->where('bet_type', $betType)
                        ->where('currency', $currency)
                        ->where('user_type', $userType)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('odd_settings')->insert([
                        'bet_type' => $betType,
                        'currency' => $currency,
                        'user_type' => $userType,
                        'odd' => $source->odd,
                        'is_active' => (bool) $source->is_active,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function collapseToSingleRowPerBetType(): void
    {
        $betTypes = DB::table('odd_settings')
            ->select('bet_type')
            ->distinct()
            ->pluck('bet_type');

        foreach ($betTypes as $betType) {
            $keeperId = DB::table('odd_settings')
                ->where('bet_type', $betType)
                ->orderBy('id')
                ->value('id');

            if ($keeperId === null) {
                continue;
            }

            DB::table('odd_settings')
                ->where('bet_type', $betType)
                ->where('id', '!=', $keeperId)
                ->delete();
        }
    }

    private function dropBetTypeUniqueIndex(): void
    {
        try {
            Schema::table('odd_settings', function (Blueprint $table): void {
                $table->dropUnique('odd_settings_bet_type_unique');
            });
        } catch (\Throwable) {
        }
    }

    private function ensureBetTypeUniqueIndex(): void
    {
        try {
            Schema::table('odd_settings', function (Blueprint $table): void {
                $table->unique('bet_type', 'odd_settings_bet_type_unique');
            });
        } catch (\Throwable) {
        }
    }

    private function ensureCompositeUniqueIndex(): void
    {
        try {
            Schema::table('odd_settings', function (Blueprint $table): void {
                $table->unique(
                    ['bet_type', 'currency', 'user_type'],
                    'odd_settings_bet_type_currency_user_type_unique'
                );
            });
        } catch (\Throwable) {
        }
    }

    private function dropCompositeUniqueIndex(): void
    {
        try {
            Schema::table('odd_settings', function (Blueprint $table): void {
                $table->dropUnique('odd_settings_bet_type_currency_user_type_unique');
            });
        } catch (\Throwable) {
        }
    }
};
