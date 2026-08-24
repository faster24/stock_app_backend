<?php

namespace Tests\Feature\PopupAd;

use App\Models\PopupAd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UploadsScriptableImages;
use Tests\TestCase;

class PopupAdApiTest extends TestCase
{
    use RefreshDatabase, UploadsScriptableImages;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bet_slips');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function adWithImage(array $attributes = []): PopupAd
    {
        $ad = PopupAd::factory()->create($attributes);
        $ad->addMedia(UploadedFile::fake()->image('ad.jpg'))->toMediaCollection('ad_image');

        return $ad->fresh();
    }

    public function test_admin_can_create_update_and_delete_popup_ad(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->tokenFor($admin);

        $createResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/popup-ads', [
                'title' => 'Songkran promo',
                'link_url' => 'https://zarmani108.uk/promo',
                'is_active' => true,
                'image' => UploadedFile::fake()->image('promo.jpg'),
            ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('message', 'Popup ad created successfully.')
            ->assertJsonPath('data.popup_ad.title', 'Songkran promo')
            ->assertJsonPath('data.popup_ad.is_active', true)
            ->assertJsonPath('data.popup_ad.image.exists', true)
            ->assertJsonPath('errors', null);

        $adId = $createResponse->json('data.popup_ad.id');
        $this->assertDatabaseHas('popup_ads', ['id' => $adId, 'title' => 'Songkran promo']);

        $updateResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/popup-ads/'.$adId, [
                'title' => 'Songkran promo (extended)',
                'is_active' => false,
            ]);

        $updateResponse
            ->assertOk()
            ->assertJsonPath('data.popup_ad.title', 'Songkran promo (extended)')
            ->assertJsonPath('data.popup_ad.is_active', false)
            // The artwork survives an update that carries no new file.
            ->assertJsonPath('data.popup_ad.image.exists', true)
            ->assertJsonPath('errors', null);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/popup-ads/'.$adId)
            ->assertOk()
            ->assertJsonPath('message', 'Popup ad deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('popup_ads', ['id' => $adId]);
    }

    /**
     * The dashboard cannot send a real PUT with a file (PHP leaves $_FILES empty),
     * so it POSTs with _method=PUT. This is that exact request.
     */
    public function test_image_can_be_replaced_via_method_spoofed_post(): void
    {
        $admin = User::factory()->admin()->create();
        $ad = $this->adWithImage();
        $originalFileName = $ad->getFirstMedia('ad_image')->file_name;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->post('/api/v1/admin/popup-ads/'.$ad->id, [
                '_method' => 'PUT',
                'title' => 'Replaced artwork',
                'image' => UploadedFile::fake()->image('replacement.jpg'),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.popup_ad.title', 'Replaced artwork')
            ->assertJsonPath('data.popup_ad.image.exists', true)
            ->assertJsonPath('data.popup_ad.image.file_name', 'replacement.jpg');

        // singleFile() replaces rather than accumulates.
        $this->assertNotSame($originalFileName, $ad->fresh()->getFirstMedia('ad_image')->file_name);
        $this->assertCount(1, $ad->fresh()->getMedia('ad_image'));
    }

    public function test_create_requires_an_image(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->postJson('/api/v1/admin/popup-ads', ['title' => 'No artwork'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);
    }

    /**
     * The allowlist has to stay wide enough for the formats the dashboard sends;
     * a fix that only ever rejects is not a fix.
     */
    public function test_png_and_webp_artwork_are_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['artwork.png', 'artwork.webp'] as $fileName) {
            $this->app['auth']->forgetGuards();

            $this
                ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
                ->postJson('/api/v1/admin/popup-ads', [
                    'title' => 'Promo '.$fileName,
                    'image' => UploadedFile::fake()->image($fileName),
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.popup_ad.image.exists', true);
        }
    }

    public function test_svg_artwork_is_rejected_on_create(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->postJson('/api/v1/admin/popup-ads', [
                'title' => 'Scriptable artwork',
                'image' => $this->scriptableSvgUpload(),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertDatabaseMissing('popup_ads', ['title' => 'Scriptable artwork']);
    }

    /**
     * An SVG is an executable document, so the name it arrives under proves
     * nothing — validation has to read the bytes.
     */
    public function test_svg_renamed_to_png_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->postJson('/api/v1/admin/popup-ads', [
                'title' => 'Disguised artwork',
                'image' => $this->scriptableSvgUpload('logo.png'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertDatabaseMissing('popup_ads', ['title' => 'Disguised artwork']);
    }

    public function test_rejected_svg_replacement_leaves_the_existing_artwork_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $ad = $this->adWithImage(['title' => 'Original']);
        $originalFileName = $ad->getFirstMedia('ad_image')->file_name;

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->post('/api/v1/admin/popup-ads/'.$ad->id, [
                '_method' => 'PUT',
                'title' => 'Replaced with a payload',
                'image' => $this->scriptableSvgUpload(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $fresh = $ad->fresh();
        $this->assertSame('Original', $fresh->title);
        $this->assertSame($originalFileName, $fresh->getFirstMedia('ad_image')->file_name);
    }

    public function test_image_download_forbids_content_type_sniffing(): void
    {
        $user = User::factory()->normalUser()->create();
        $ad = $this->adWithImage(['is_active' => true]);

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->get('/api/v1/popup-ads/'.$ad->id.'/image')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_guest_cannot_read_popup_ads(): void
    {
        $this->getJson('/api/v1/popup-ads')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_non_admin_cannot_write_popup_ads(): void
    {
        $ad = $this->adWithImage();
        $user = User::factory()->normalUser()->create();
        $token = $this->tokenFor($user);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/popup-ads', [
                'title' => 'Sneaky ad',
                'image' => UploadedFile::fake()->image('sneaky.jpg'),
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/popup-ads/'.$ad->id, ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/popup-ads/'.$ad->id)
            ->assertStatus(403);
    }

    public function test_client_list_returns_only_active_ads_newest_first(): void
    {
        $older = $this->adWithImage(['title' => 'Older active']);
        $this->adWithImage(['title' => 'Disabled', 'is_active' => false]);
        $newer = $this->adWithImage(['title' => 'Newer active']);

        // created_at ties would make the ordering assertion meaningless.
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $user = User::factory()->normalUser()->create();

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/popup-ads');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Popup ads retrieved successfully.')
            ->assertJsonCount(2, 'data.popup_ads')
            ->assertJsonPath('data.popup_ads.0.id', $newer->id)
            ->assertJsonPath('data.popup_ads.1.id', $older->id)
            ->assertJsonPath('errors', null);
    }

    public function test_image_download_follows_the_active_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $ad = $this->adWithImage();

        $user = User::factory()->normalUser()->create();

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->get('/api/v1/popup-ads/'.$ad->id.'/image')
            ->assertOk();

        // Sanctum caches the resolved user on the guard for the whole test; reset
        // before switching tokens or the admin call runs as the player.
        $this->app['auth']->forgetGuards();

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->putJson('/api/v1/admin/popup-ads/'.$ad->id, ['is_active' => false])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/popup-ads/'.$ad->id.'/image')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Popup ad not found.');

        $this->app['auth']->forgetGuards();

        // The admin still sees it, so the dashboard can preview a disabled ad.
        $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->get('/api/v1/popup-ads/'.$ad->id.'/image')
            ->assertOk();
    }
}
