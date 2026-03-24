<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bet_numbers') || ! Schema::hasColumn('bet_numbers', 'number')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE bet_numbers MODIFY number SMALLINT UNSIGNED NOT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bet_numbers ALTER COLUMN number TYPE SMALLINT');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bet_numbers') || ! Schema::hasColumn('bet_numbers', 'number')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE bet_numbers MODIFY number TINYINT UNSIGNED NOT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bet_numbers ALTER COLUMN number TYPE SMALLINT');
        }
    }
};
