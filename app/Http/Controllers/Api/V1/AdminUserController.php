<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\AssignUserRoleRequest;
use App\Models\User;
use App\Services\User\UserManagementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(private UserManagementService $userManagementService) {}

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        return $this->respond('Users retrieved successfully.', [
            'users' => $this->userManagementService
                ->listActiveUsers($page, $pageSize)
                ->map(fn (User $user): array => $this->userSummaryPayload($user))
                ->values()
                ->all(),
        ]);
    }

    public function show(string $user): JsonResponse
    {
        $resolvedUser = $this->userManagementService->showUser($user);

        if (! $resolvedUser instanceof User) {
            return $this->respond('User not found.', null, 404, [
                'user' => ['The selected user is invalid.'],
            ]);
        }

        return $this->respond('User retrieved successfully.', [
            'user' => $this->userDetailPayload($resolvedUser),
        ]);
    }

    public function ban(Request $request, string $user): JsonResponse
    {
        $resolvedUser = $this->userManagementService->showUser($user);

        if (! $resolvedUser instanceof User) {
            return $this->respond('User not found.', null, 404, [
                'user' => ['The selected user is invalid.'],
            ]);
        }

        try {
            $bannedUser = $this->userManagementService->banUser((string) $request->user()->id, $resolvedUser);
        } catch (DomainException $exception) {
            return $this->respond('The given data was invalid.', null, 422, [
                'user' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('User banned successfully.', [
            'user' => $this->userDetailPayload($bannedUser),
        ]);
    }

    public function unban(Request $request, string $user): JsonResponse
    {
        $resolvedUser = $this->userManagementService->showUser($user);

        if (! $resolvedUser instanceof User) {
            return $this->respond('User not found.', null, 404, [
                'user' => ['The selected user is invalid.'],
            ]);
        }

        try {
            $unbannedUser = $this->userManagementService->unbanUser((string) $request->user()->id, $resolvedUser);
        } catch (DomainException $exception) {
            return $this->respond('The given data was invalid.', null, 422, [
                'user' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('User unbanned successfully.', [
            'user' => $this->userDetailPayload($unbannedUser),
        ]);
    }

    public function destroy(Request $request, string $user): JsonResponse
    {
        $resolvedUser = $this->userManagementService->showUser($user);

        if (! $resolvedUser instanceof User) {
            return $this->respond('User not found.', null, 404, [
                'user' => ['The selected user is invalid.'],
            ]);
        }

        try {
            $this->userManagementService->deleteUser((string) $request->user()->id, $resolvedUser);
        } catch (DomainException $exception) {
            return $this->respond('The given data was invalid.', null, 422, [
                'user' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('User deleted successfully.', null);
    }

    public function assignRole(AssignUserRoleRequest $request, string $user): JsonResponse
    {
        $resolvedUser = $this->userManagementService->showUser($user);

        if (! $resolvedUser instanceof User) {
            return $this->respond('User not found.', null, 404, [
                'user' => ['The selected user is invalid.'],
            ]);
        }

        try {
            $updatedUser = $this->userManagementService->assignCustomerRole(
                (string) $request->user()->id,
                $resolvedUser,
                (string) $request->validated()['role'],
            );
        } catch (DomainException $exception) {
            return $this->respond('The given data was invalid.', null, 422, [
                'user' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('User role updated successfully.', [
            'user' => $this->userDetailPayload($updatedUser),
        ]);
    }

    private function userSummaryPayload(User $user): array
    {
        $roleNames = $user->getRoleNames()->values()->all();

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $this->resolveCustomerRole($roleNames),
            'roles' => $roleNames,
            'is_banned' => (bool) $user->is_banned,
            'banned_at' => $user->banned_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }

    private function userDetailPayload(User $user): array
    {
        return [
            ...$this->userSummaryPayload($user),
            'bank_info' => [
                'bank_name' => optional($user->wallet)->bank_name?->value ?? optional($user->wallet)->bank_name,
                'account_name' => optional($user->wallet)->account_name,
                'account_number' => optional($user->wallet)->account_number,
            ],
        ];
    }

    private function respond(string $message, ?array $data, int $status = 200, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $status);
    }

    private function resolveCustomerRole(array $roles): ?string
    {
        if (in_array('vip', $roles, true)) {
            return 'vip';
        }

        if (in_array('user', $roles, true)) {
            return 'user';
        }

        return null;
    }
}
