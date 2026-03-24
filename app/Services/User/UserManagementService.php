<?php

namespace App\Services\User;

use App\Models\User;
use App\Services\Service;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

class UserManagementService extends Service
{
    private const CUSTOMER_ROLES = ['user', 'vip'];

    public function listActiveUsers(int $page = 1, int $pageSize = 10): Collection
    {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(100, max(1, $pageSize));

        return User::query()
            ->with('roles')
            ->latest()
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function showUser(int $userId): ?User
    {
        return User::query()
            ->with(['wallet', 'roles'])
            ->whereKey($userId)
            ->first();
    }

    public function banUser(int $adminUserId, User $user): User
    {
        $this->assertNotSelfAction($adminUserId, (int) $user->id);

        $user->forceFill([
            'is_banned' => true,
            'banned_at' => Carbon::now(),
        ])->save();

        $user->tokens()->delete();

        return $user->fresh(['wallet', 'roles']);
    }

    public function unbanUser(int $adminUserId, User $user): User
    {
        $this->assertNotSelfAction($adminUserId, (int) $user->id);

        $user->forceFill([
            'is_banned' => false,
            'banned_at' => null,
        ])->save();

        return $user->fresh(['wallet', 'roles']);
    }

    public function assignCustomerRole(int $adminUserId, User $user, string $role): User
    {
        $this->assertNotSelfAction($adminUserId, (int) $user->id);

        if (! in_array($role, self::CUSTOMER_ROLES, true)) {
            throw new DomainException('Unsupported role.');
        }

        $guard = Guard::getDefaultName($user);
        Role::findOrCreate($role, $guard);

        $retainedRoles = array_values(array_filter(
            $user->getRoleNames()->all(),
            static fn (string $currentRole): bool => ! in_array($currentRole, self::CUSTOMER_ROLES, true)
        ));

        $user->syncRoles([...$retainedRoles, $role]);

        return $user->fresh(['wallet', 'roles']);
    }

    public function deleteUser(int $adminUserId, User $user): void
    {
        $this->assertNotSelfAction($adminUserId, (int) $user->id);

        $user->tokens()->delete();
        $user->delete();
    }

    private function assertNotSelfAction(int $adminUserId, int $targetUserId): void
    {
        if ($adminUserId === $targetUserId) {
            throw new DomainException('You cannot manage your own account.');
        }
    }
}
