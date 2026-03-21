<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_banned')->default(false)->after('password')->index();
            $table->timestamp('banned_at')->nullable()->after('is_banned');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('users', 'banned_at')) {
                $table->dropColumn('banned_at');
            }

            if (Schema::hasColumn('users', 'is_banned')) {
                $table->dropIndex('users_is_banned_index');
                $table->dropColumn('is_banned');
            }
        });
    }
};
