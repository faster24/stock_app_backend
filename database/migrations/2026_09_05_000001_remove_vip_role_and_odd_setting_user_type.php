<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateVipUsersToUserRole();
        $this->dropVipOddSettings();
        $this->narrowUserTypeEnum(['user']);

        $this->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->narrowUserTypeEnum(['user', 'vip']);
        $this->restoreVipRole();

        $this->forgetCachedPermissions();
    }

    private function migrateVipUsersToUserRole(): void
    {
        $rolesTable = $this->rolesTable();
        $pivotTable = $this->modelHasRolesTable();

        if (! Schema::hasTable($rolesTable) || ! Schema::hasTable($pivotTable)) {
            return;
        }

        $roleForeignKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');

        $vipRoles = DB::table($rolesTable)->where('name', 'vip')->get(['id', 'guard_name']);

        foreach ($vipRoles as $vipRole) {
            $userRoleId = DB::table($rolesTable)
                ->where('name', 'user')
                ->where('guard_name', $vipRole->guard_name)
                ->value('id');

            $assignments = DB::table($pivotTable)->where($roleForeignKey, $vipRole->id)->get();

            if ($userRoleId !== null) {
                foreach ($assignments as $assignment) {
                    DB::table($pivotTable)->insertOrIgnore([
                        $roleForeignKey => $userRoleId,
                        'model_type' => $assignment->model_type,
                        $morphKey => $assignment->{$morphKey},
                    ]);
                }
            }

            DB::table($pivotTable)->where($roleForeignKey, $vipRole->id)->delete();
            DB::table($rolesTable)->where('id', $vipRole->id)->delete();
        }
    }

    private function restoreVipRole(): void
    {
        $rolesTable = $this->rolesTable();

        if (! Schema::hasTable($rolesTable)) {
            return;
        }

        $guardName = DB::table($rolesTable)->where('name', 'user')->value('guard_name') ?? 'web';

        $exists = DB::table($rolesTable)
            ->where('name', 'vip')
            ->where('guard_name', $guardName)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table($rolesTable)->insert([
            'name' => 'vip',
            'guard_name' => $guardName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dropVipOddSettings(): void
    {
        if (! Schema::hasTable('odd_settings') || ! Schema::hasColumn('odd_settings', 'user_type')) {
            return;
        }

        DB::table('odd_settings')->where('user_type', 'vip')->delete();
    }

    /**
     * @param  array<int, string>  $values
     */
    private function narrowUserTypeEnum(array $values): void
    {
        if (! Schema::hasTable('odd_settings') || ! Schema::hasColumn('odd_settings', 'user_type')) {
            return;
        }

        $enumValues = implode(',', array_map(
            static fn (string $value): string => "'".$value."'",
            $values
        ));

        DB::statement(
            "ALTER TABLE `odd_settings` MODIFY `user_type` ENUM({$enumValues}) NOT NULL DEFAULT 'user'"
        );
    }

    private function forgetCachedPermissions(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
        }
    }

    private function rolesTable(): string
    {
        return config('permission.table_names.roles', 'roles');
    }

    private function modelHasRolesTable(): string
    {
        return config('permission.table_names.model_has_roles', 'model_has_roles');
    }
};
