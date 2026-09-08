<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'phone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'phone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_phone_unique');
            $table->dropColumn('phone');
        });
    }
};
