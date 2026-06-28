<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->normalUser()->create();
    }

    private function actingAsUser(): static
    {
        $bearer = $this->user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$bearer);
    }

    private function createLog(string $userId, ?string $readAt = null): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $userId,
            'title' => 'Test',
            'body' => 'Test body',
            'notification_type' => 'test',
            'data' => [],
            'status' => 'sent',
            'sent_at' => now(),
            'read_at' => $readAt,
        ]);
    }

    public function test_stats_reports_unread_count(): void
    {
        $this->createLog($this->user->id);
        $this->createLog($this->user->id);
        $this->createLog($this->user->id, now()->toDateTimeString());

        $response = $this->actingAsUser()->getJson('/api/v1/notifications/stats');

        $response->assertOk()->assertJsonPath('data.unread', 2);
    }

    public function test_logs_response_includes_read_at(): void
    {
        $log = $this->createLog($this->user->id);

        $response = $this->actingAsUser()->getJson('/api/v1/notifications/logs');

        $response->assertOk();
        $this->assertArrayHasKey('read_at', $response->json('data.data.0'));
        $this->assertSame($log->id, $response->json('data.data.0.id'));
    }

    public function test_mark_all_as_read_marks_unread_logs(): void
    {
        $this->createLog($this->user->id);
        $this->createLog($this->user->id);
        $alreadyRead = $this->createLog($this->user->id, now()->subDay()->toDateTimeString());

        $response = $this->actingAsUser()->postJson('/api/v1/notifications/read-all');

        $response->assertOk()->assertJsonPath('updated_count', 2);

        $unread = NotificationLog::where('user_id', $this->user->id)->whereNull('read_at')->count();
        $this->assertSame(0, $unread);

        // Already-read log's original read_at must be untouched (not re-stamped to "now")
        $alreadyRead->refresh();
        $this->assertTrue($alreadyRead->read_at->lt(now()->subHours(23)));
    }

    public function test_mark_all_as_read_does_not_affect_other_users(): void
    {
        $other = User::factory()->normalUser()->create();
        $this->createLog($other->id);

        $this->actingAsUser()->postJson('/api/v1/notifications/read-all')->assertOk();

        $unreadForOther = NotificationLog::where('user_id', $other->id)->whereNull('read_at')->count();
        $this->assertSame(1, $unreadForOther);
    }

    public function test_guest_cannot_mark_all_as_read(): void
    {
        $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);
    }
}
