<?php

namespace Tests\Feature\Announcement;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_and_show_announcements(): void
    {
        $announcement = Announcement::query()->create([
            'title' => 'System Maintenance',
            'summary' => 'Planned maintenance window.',
            'description' => 'The system will be unavailable for 30 minutes.',
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $listResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/announcements');

        $listResponse
            ->assertOk()
            ->assertJsonPath('message', 'Announcements retrieved successfully.')
            ->assertJsonPath('data.announcements.0.id', $announcement->id)
            ->assertJsonPath('errors', null);

        $showResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/announcements/'.$announcement->id);

        $showResponse
            ->assertOk()
            ->assertJsonPath('message', 'Announcement retrieved successfully.')
            ->assertJsonPath('data.announcement.id', $announcement->id)
            ->assertJsonPath('errors', null);
    }

    public function test_guest_cannot_read_announcements(): void
    {
        $announcement = Announcement::query()->create([
            'title' => 'Holiday Notice',
            'summary' => 'Office closure information.',
            'description' => 'The office will remain closed on the public holiday.',
        ]);

        $this->getJson('/api/v1/announcements')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');

        $this->getJson('/api/v1/announcements/'.$announcement->id)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_non_admin_cannot_write_announcements(): void
    {
        $announcement = Announcement::query()->create([
            'title' => 'Initial title',
            'summary' => 'Initial summary.',
            'description' => 'Initial description.',
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $payload = [
            'title' => 'Updated title',
            'summary' => 'Updated summary',
            'description' => 'Updated description',
        ];

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/announcements', $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/announcements/'.$announcement->id, $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/announcements/'.$announcement->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_admin_can_create_update_and_delete_announcement(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $createResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/announcements', [
                'title' => 'Service Update',
                'summary' => 'Important service changes.',
                'description' => 'A new feature rollout is scheduled this week.',
            ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('message', 'Announcement created successfully.')
            ->assertJsonPath('data.announcement.title', 'Service Update')
            ->assertJsonPath('errors', null);

        $announcementId = $createResponse->json('data.announcement.id');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcementId,
            'title' => 'Service Update',
        ]);

        $updateResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/announcements/'.$announcementId, [
                'title' => 'Service Update v2',
                'summary' => 'Updated summary',
                'description' => 'Updated description',
            ]);

        $updateResponse
            ->assertOk()
            ->assertJsonPath('message', 'Announcement updated successfully.')
            ->assertJsonPath('data.announcement.title', 'Service Update v2')
            ->assertJsonPath('errors', null);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcementId,
            'title' => 'Service Update v2',
        ]);

        $deleteResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/announcements/'.$announcementId);

        $deleteResponse
            ->assertOk()
            ->assertJsonPath('message', 'Announcement deleted successfully.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseMissing('announcements', [
            'id' => $announcementId,
        ]);
    }

    public function test_create_and_update_validation_errors_return_422_envelope_with_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $createResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/announcements', [
                'title' => '',
                'summary' => '',
                'description' => '',
            ]);

        $createResponse
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['title', 'summary', 'description'],
            ]);

        $announcement = Announcement::query()->create([
            'title' => 'Current title',
            'summary' => 'Current summary',
            'description' => 'Current description',
        ]);

        $updateResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/announcements/'.$announcement->id, [
                'title' => '',
            ]);

        $updateResponse
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['title'],
            ]);
    }
}
