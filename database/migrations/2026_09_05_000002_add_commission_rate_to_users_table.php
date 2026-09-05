<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'commission_rate')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'commission_rate')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('commission_rate');
        });
    }
};
