<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AiUsageService;
use App\Models\AiUsageLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected AiUsageService $aiUsage;
    protected Business $business;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->user->id]);
        $this->aiUsage = new AiUsageService($this->business->id);
    }

    public function test_estimates_cost_for_gpt4o_mini(): void
    {
        // AiUsageService::estimateCost(model, inputTokens, outputTokens)
        $cost = $this->aiUsage->estimateCost('gpt-4o-mini', 1000, 500);

        // Input: 1000 * $0.15 / 1M = $0.00015
        // Output: 500 * $0.60 / 1M = $0.0003
        // Total: $0.00045
        $this->assertEqualsWithDelta(0.00045, $cost, 0.00001);
    }

    public function test_estimates_cost_for_gemini_flash(): void
    {
        $cost = $this->aiUsage->estimateCost('gemini-1.5-flash', 1000, 500);

        // Input: 1000 * $0.075 / 1M = $0.000075
        // Output: 500 * $0.30 / 1M = $0.00015
        // Total: $0.000225
        $this->assertEqualsWithDelta(0.000225, $cost, 0.00001);
    }

    public function test_tracks_usage_creates_log(): void
    {
        $result = $this->aiUsage->trackUsage(
            usage: ['prompt_tokens' => 500, 'completion_tokens' => 200],
            agentName: 'caption_writer',
            operation: 'generate_caption',
            model: 'gpt-4o-mini',
            userId: $this->user->id
        );

        $this->assertArrayHasKey('cost_usd', $result);
        $this->assertArrayHasKey('input_tokens', $result);
        $this->assertEquals(500, $result['input_tokens']);
        $this->assertEquals(200, $result['output_tokens']);
        $this->assertGreaterThan(0, $result['cost_usd']);
        
        // Verify database record exists
        $this->assertDatabaseHas('ai_usage_logs', [
            'business_id' => $this->business->id,
            'agent_name' => 'caption_writer',
        ]);
    }

    public function test_get_usage_summary(): void
    {
        // Create some usage logs
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'caption_writer', 'generate', 'gpt-4o-mini', $this->user->id
        );
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 200, 'completion_tokens' => 100],
            'hashtag_researcher', 'research', 'gpt-4o-mini', $this->user->id
        );

        $summary = $this->aiUsage->getUsageSummary();

        $this->assertArrayHasKey('total_input_tokens', $summary);
        $this->assertArrayHasKey('total_output_tokens', $summary);
        $this->assertArrayHasKey('total_cost_usd', $summary);
        $this->assertArrayHasKey('api_calls', $summary);
        $this->assertEquals(2, $summary['api_calls']);
        $this->assertEquals(300, $summary['total_input_tokens']);
        $this->assertEquals(150, $summary['total_output_tokens']);
    }

    public function test_get_usage_by_agent(): void
    {
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'caption_writer', 'generate', 'gpt-4o-mini', $this->user->id
        );
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'caption_writer', 'rewrite', 'gpt-4o-mini', $this->user->id
        );
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'hashtag_researcher', 'research', 'gpt-4o-mini', $this->user->id
        );

        $byAgent = $this->aiUsage->getUsageByAgent();

        $this->assertCount(2, $byAgent);
    }

    public function test_has_quota_remaining_with_no_usage(): void
    {
        $this->assertTrue($this->aiUsage->hasQuotaRemaining(10.00));
    }

    public function test_has_quota_remaining_with_free_plan(): void
    {
        // Free plan has token limit
        $this->assertTrue($this->aiUsage->hasQuotaRemaining());
    }

    public function test_get_usage_by_operation(): void
    {
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'caption_writer', 'generate', 'gpt-4o-mini', $this->user->id
        );
        $this->aiUsage->trackUsage(
            ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'caption_writer', 'rewrite', 'gpt-4o-mini', $this->user->id
        );

        $byOperation = $this->aiUsage->getUsageByOperation();

        $this->assertCount(2, $byOperation);
    }

    public function test_pricing_defaults_for_unknown_model(): void
    {
        // Unknown model should use default pricing (gpt-4o-mini)
        $cost = $this->aiUsage->estimateCost('unknown-model', 1000, 500);

        // Should use gpt-4o-mini pricing
        $this->assertEqualsWithDelta(0.00045, $cost, 0.00001);
    }
}
