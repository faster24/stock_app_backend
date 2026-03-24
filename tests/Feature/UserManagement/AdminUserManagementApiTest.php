<?php

namespace Tests\Feature\UserManagement;

use App\Enums\BankName;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_admin_user_management_endpoints(): void
    {
        $user = User::factory()->normalUser()->create();

        $this->getJson('/api/v1/admin/users')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->getJson('/api/v1/admin/users/'.$user->id)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->postJson('/api/v1/admin/users/'.$user->id.'/ban')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->patchJson('/api/v1/admin/users/'.$user->id.'/role', [
            'role' => 'vip',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->postJson('/api/v1/admin/users/'.$user->id.'/unban')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->deleteJson('/api/v1/admin/users/'.$user->id)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_non_admin_cannot_access_admin_user_management_endpoints(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/users/'.$user->id.'/role', [
                'role' => 'vip',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');
    }

    public function test_admin_can_list_active_users_only(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $activeUser = User::factory()->normalUser()->create([
            'is_banned' => false,
        ]);
        $deletedUser = User::factory()->normalUser()->create();
        $deletedUser->delete();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Users retrieved successfully.')
            ->assertJsonPath('errors', null);

        $ids = collect($response->json('data.users'))->pluck('id')->all();

        $this->assertContains($activeUser->id, $ids);
        $this->assertContains($admin->id, $ids);
        $this->assertNotContains($deletedUser->id, $ids);
    }

    public function test_admin_can_view_user_details_with_bank_info(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create();
        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Managed User',
            'account_number' => '123123123',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users/'.$user->id)
            ->assertOk()
            ->assertJsonPath('message', 'User retrieved successfully.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.bank_info.bank_name', BankName::KBZ->value)
            ->assertJsonPath('data.user.bank_info.account_name', 'Managed User')
            ->assertJsonPath('data.user.bank_info.account_number', '123123123')
            ->assertJsonPath('errors', null);
    }

    public function test_admin_can_ban_and_unban_user_and_ban_revokes_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create([
            'is_banned' => false,
            'banned_at' => null,
        ]);
        $user->createToken('device-a');
        $user->createToken('device-b');

        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/admin/users/'.$user->id.'/ban')
            ->assertOk()
            ->assertJsonPath('message', 'User banned successfully.')
            ->assertJsonPath('data.user.is_banned', true)
            ->assertJsonPath('errors', null);

        $user->refresh();
        $this->assertTrue((bool) $user->is_banned);
        $this->assertNotNull($user->banned_at);
        $this->assertSame(0, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/admin/users/'.$user->id.'/unban')
            ->assertOk()
            ->assertJsonPath('message', 'User unbanned successfully.')
            ->assertJsonPath('data.user.is_banned', false)
            ->assertJsonPath('errors', null);

        $user->refresh();
        $this->assertFalse((bool) $user->is_banned);
        $this->assertNull($user->banned_at);
    }

    public function test_admin_can_assign_vip_role_and_switch_back_to_user(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;
        $user = User::factory()->normalUser()->create();

        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('vip'));

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/api/v1/admin/users/'.$user->id.'/role', [
                'role' => 'vip',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User role updated successfully.')
            ->assertJsonPath('data.user.role', 'vip')
            ->assertJsonPath('errors', null);

        $user->refresh();
        $this->assertTrue($user->hasRole('vip'));
        $this->assertFalse($user->hasRole('user'));

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/api/v1/admin/users/'.$user->id.'/role', [
                'role' => 'user',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User role updated successfully.')
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonPath('errors', null);

        $user->refresh();
        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('vip'));
    }

    public function test_admin_role_assignment_rejects_invalid_role(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;
        $user = User::factory()->normalUser()->create();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/users/'.$user->id.'/role', [
                'role' => 'super_admin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['role'],
            ]);
    }

    public function test_banned_user_cannot_login_or_access_authenticated_routes(): void
    {
        $user = User::factory()->normalUser()->create([
            'email' => 'banned-user@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $user->update([
            'is_banned' => true,
            'banned_at' => now(),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'banned-user@example.com',
            'password' => 'password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'Your account is banned.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'Your account is banned.');
    }

    public function test_admin_can_soft_delete_user_and_user_is_hidden_from_list(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $user = User::factory()->normalUser()->create([
            'email' => 'to-delete@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/users/'.$user->id)
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertSoftDeleted('users', [
            'id' => $user->id,
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'to-delete@example.com',
            'password' => 'password123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials.');

        $listResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        $ids = collect($listResponse->json('data.users'))->pluck('id')->all();
        $this->assertNotContains($user->id, $ids);
    }

    public function test_admin_cannot_manage_own_account_and_edit_endpoint_is_not_available(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/users/'.$admin->id.'/ban')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.user.0', 'You cannot manage your own account.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/users/'.$admin->id.'/unban')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.user.0', 'You cannot manage your own account.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/admin/users/'.$admin->id.'/role', [
                'role' => 'vip',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.user.0', 'You cannot manage your own account.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/users/'.$admin->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.user.0', 'You cannot manage your own account.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/users/'.$admin->id, [
                'username' => 'should-not-update',
            ])
            ->assertStatus(405);
    }
}
