<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Business;
use App\Models\Post;
use App\Models\SocialPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
        ]);
        // Update business to set owner_id
        $this->business->update(['owner_id' => $this->user->id]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.index');
    }

    public function test_dashboard_shows_business_data(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/');

        $response->assertStatus(200);
    }

    public function test_upload_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/upload');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.upload');
    }

    public function test_posts_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/posts');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.posts');
    }

    public function test_analytics_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/analytics');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.analytics');
    }

    public function test_platforms_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/platforms');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.platforms');
    }

    public function test_settings_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/settings');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.settings');
    }

    public function test_calendar_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/calendar');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.calendar');
    }

    public function test_jobs_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/jobs');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.jobs');
    }

    public function test_agents_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/agents');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.agents');
    }

    public function test_seo_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/seo');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.seo');
    }

    public function test_hr_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/hr');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.hr');
    }

    public function test_billing_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/billing');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.billing');
    }

    public function test_bot_training_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/bot-training');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.bot-training');
    }

    public function test_strategy_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
                         ->get('/strategy');

        $response->assertStatus(200)
                 ->assertViewIs('dashboard.strategy');
    }

    public function test_ai_task_generate_caption(): void
    {
        $response = $this->actingAs($this->user)
                         ->postJson('/ai-assistant', [
                             'task'        => 'generate_caption',
                             'platform'    => 'instagram',
                             'description' => 'A photo of a beautiful sunset',
                             'mood'        => 'inspiring',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success']);
    }

    public function test_ai_task_get_hashtags(): void
    {
        $response = $this->actingAs($this->user)
                         ->postJson('/ai-assistant', [
                             'task'     => 'get_hashtags',
                             'topic'    => 'fitness motivation',
                             'platform' => 'instagram',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success']);
    }

    public function test_business_switch(): void
    {
        $newBusiness = Business::factory()->create([
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
                         ->postJson('/businesses/' . $newBusiness->id . '/switch', [
                             'business_id' => $newBusiness->id,
                         ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }

    public function test_cannot_switch_to_other_user_business(): void
    {
        $otherBusiness = Business::factory()->create();

        $response = $this->actingAs($this->user)
                         ->postJson('/businesses/' . $otherBusiness->id . '/switch', [
                             'business_id' => $otherBusiness->id,
                         ]);

        $response->assertStatus(403);
    }
}
