<?php

namespace App\Services\Auth;

use App\Enums\Currency;
use App\Models\User;
use App\Services\Service;
use App\Services\Wallet\WalletCurrencyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

class AuthService extends Service
{
    public function __construct(private WalletCurrencyService $walletCurrencyService) {}

    public function register(string $username, string $email, string $password, ?Currency $currency, string $pin): array
    {
        $result = DB::transaction(function () use ($username, $email, $password, $currency, $pin) {
            $user = User::query()->create([
                'username'            => $username,
                'email'               => $email,
                'password'            => Hash::make($password),
                'security_pin'        => $pin,
                'security_pin_set_at' => now(),
            ]);

            $guard = Guard::getDefaultName($user);
            $role = Role::findOrCreate('user', $guard);
            $user->assignRole($role);

            if ($currency !== null) {
                $this->walletCurrencyService->setForUser($user->id, $currency);
            }

            return [
                'user'  => $user,
                'token' => $user->createToken('auth_token')->plainTextToken,
            ];
        });

        return $result;
    }

    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        if ($user->is_banned) {
            throw new AuthorizationException('Your account is banned.');
        }

        return [
            'user' => $this->userPayload($user),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ];
    }

    public function me(User $user): array
    {
        return $this->userPayload($user);
    }

    private function userPayload(User $user): array
    {
        $roleNames = $user->getRoleNames()->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->username,
            'username' => $user->username,
            'email' => $user->email,
            'role' => in_array('vip', $roleNames, true) ? 'vip' : (in_array('user', $roleNames, true) ? 'user' : null),
            'roles' => $roleNames,
            'is_banned' => (bool) $user->is_banned,
            'banned_at' => $user->banned_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
