<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
        ]);
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
                 ->assertJson(['status' => 'ok']);
    }

    public function test_telegram_webhook_rejects_invalid_token(): void
    {
        $response = $this->postJson('/api/telegram/webhook/invalid_token', [
            'message' => ['text' => '/start'],
        ]);

        $response->assertStatus(403)
                 ->assertJson(['error' => 'Invalid token']);
    }

    public function test_authenticated_user_endpoint(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/user');

        $response->assertStatus(200)
                 ->assertJsonStructure(['id', 'name', 'email']);
    }

    public function test_businesses_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/businesses');

        $response->assertStatus(401);
    }

    public function test_businesses_endpoint_returns_user_businesses(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/businesses');

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'businesses']);
    }

    public function test_generate_caption_requires_business_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/generate/caption', [
                             'prompt' => 'Test prompt',
                         ]);

        $response->assertStatus(422);
    }

    public function test_generate_caption_validates_business_ownership(): void
    {
        $otherBusiness = Business::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/generate/caption', [
                             'business_id' => $otherBusiness->id,
                             'prompt'      => 'Test prompt',
                         ]);

        $response->assertStatus(404)
                 ->assertJson(['error' => 'Business not found']);
    }

    public function test_generate_hashtags_endpoint(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/generate/hashtags', [
                             'business_id' => $this->business->id,
                             'topic'       => 'fitness motivation',
                         ]);

        $response->assertStatus(200);
    }

    public function test_posts_endpoint_returns_user_posts(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/posts?business_id=' . $this->business->id);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'posts']);
    }

    public function test_create_post_endpoint(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/posts', [
                             'business_id' => $this->business->id,
                             'caption'     => 'Test post caption',
                             'platform'    => 'instagram',
                         ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['success', 'post']);
    }

    public function test_analytics_summary_requires_business_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/analytics/summary');

        $response->assertStatus(400)
                 ->assertJson(['error' => 'business_id required']);
    }
}
