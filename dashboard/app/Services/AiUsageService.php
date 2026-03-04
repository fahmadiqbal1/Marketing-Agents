<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\Business;
use App\Models\Plan;
use Illuminate\Support\Facades\Log;

/**
 * AI Usage Tracker — meters every OpenAI / Gemini API call per tenant.
 *
 *
 * Tracks token counts, estimates cost, enforces plan quotas, and logs
 * usage to the ai_usage_logs table for billing.
 *
 * Pricing (as of 2025):
 *   GPT-4o-mini:   $0.15 / 1M input tokens,  $0.60 / 1M output tokens
 *   Gemini Flash:  $0.075 / 1M input tokens,  $0.30 / 1M output tokens
 */
class AiUsageService
{
    /**
     * Pricing per million tokens.
     */
    protected const MODEL_PRICING = [
        'gpt-4o-mini'            => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o'                 => ['input' => 2.50, 'output' => 10.00],
        'gpt-4-turbo'            => ['input' => 10.00, 'output' => 30.00],
        'gemini-2.0-flash'       => ['input' => 0.075, 'output' => 0.30],
        'gemini-1.5-flash'       => ['input' => 0.075, 'output' => 0.30],
        'gemini-1.5-pro'         => ['input' => 1.25, 'output' => 5.00],
        'omni-moderation-latest' => ['input' => 0.0, 'output' => 0.0], // Free
    ];

    /**
     * Default plan limits (monthly tokens).
     */
    protected const PLAN_LIMITS = [
        'free'       => 100_000,
        'starter'    => 1_000_000,
        'pro'        => 5_000_000,
        'enterprise' => 50_000_000,
    ];

    protected int $businessId;

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    /**
     * Estimate USD cost for a single API call.
     */
    public function estimateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = self::MODEL_PRICING[$model] ?? self::MODEL_PRICING['gpt-4o-mini'];
        $cost = ($inputTokens * $pricing['input'] + $outputTokens * $pricing['output']) / 1_000_000;
        return round($cost, 6);
    }

    /**
     * Track OpenAI usage from a response and log it.
     */
    public function trackUsage(
        array $usage,
        string $agentName,
        string $operation,
        string $model = 'gpt-4o-mini',
        ?int $userId = null
    ): array {
        $inputTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
        $totalTokens = $inputTokens + $outputTokens;
        $costUsd = $this->estimateCost($model, $inputTokens, $outputTokens);

        // Log to database
        try {
            AiUsageLog::create([
                'business_id'   => $this->businessId,
                'user_id'       => $userId,
                'agent_name'    => $agentName,
                'model'         => $model,
                'operation'     => $operation,
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd'      => $costUsd,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log AI usage', ['error' => $e->getMessage()]);
        }

        return [
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens'  => $totalTokens,
            'cost_usd'      => $costUsd,
        ];
    }

    /**
     * Get usage summary for the current billing period.
     */
    public function getUsageSummary(?string $period = null): array
    {
        $startOfMonth = $period ? \Carbon\Carbon::parse($period)->startOfMonth() : now()->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $usage = AiUsageLog::where('business_id', $this->businessId)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->selectRaw('
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(cost_usd) as total_cost,
                COUNT(*) as api_calls
            ')
            ->first();

        $totalTokens = ($usage->total_input_tokens ?? 0) + ($usage->total_output_tokens ?? 0);
        $limit = $this->getTokenLimit();

        return [
            'period'             => $startOfMonth->format('Y-m'),
            'total_input_tokens' => (int) ($usage->total_input_tokens ?? 0),
            'total_output_tokens'=> (int) ($usage->total_output_tokens ?? 0),
            'total_tokens'       => $totalTokens,
            'total_cost_usd'     => round($usage->total_cost ?? 0, 4),
            'api_calls'          => (int) ($usage->api_calls ?? 0),
            'token_limit'        => $limit,
            'usage_percent'      => $limit > 0 ? round(($totalTokens / $limit) * 100, 1) : 0,
            'remaining_tokens'   => max(0, $limit - $totalTokens),
        ];
    }

    /**
     * Get usage by agent.
     */
    public function getUsageByAgent(?string $period = null): array
    {
        $startOfMonth = $period ? \Carbon\Carbon::parse($period)->startOfMonth() : now()->startOfMonth();

        return AiUsageLog::where('business_id', $this->businessId)
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('
                agent_name,
                SUM(input_tokens + output_tokens) as total_tokens,
                SUM(cost_usd) as total_cost,
                COUNT(*) as api_calls
            ')
            ->groupBy('agent_name')
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn($row) => [
                'agent'        => $row->agent_name,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost'   => round($row->total_cost, 4),
                'api_calls'    => (int) $row->api_calls,
            ])
            ->toArray();
    }

    /**
     * Get usage by operation.
     */
    public function getUsageByOperation(?string $period = null): array
    {
        $startOfMonth = $period ? \Carbon\Carbon::parse($period)->startOfMonth() : now()->startOfMonth();

        return AiUsageLog::where('business_id', $this->businessId)
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('
                operation,
                SUM(input_tokens + output_tokens) as total_tokens,
                SUM(cost_usd) as total_cost,
                COUNT(*) as api_calls
            ')
            ->groupBy('operation')
            ->orderByDesc('api_calls')
            ->get()
            ->map(fn($row) => [
                'operation'    => $row->operation,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost'   => round($row->total_cost, 4),
                'api_calls'    => (int) $row->api_calls,
            ])
            ->toArray();
    }

    /**
     * Check if business has quota remaining.
     */
    public function hasQuotaRemaining(): bool
    {
        $summary = $this->getUsageSummary();
        return $summary['remaining_tokens'] > 0;
    }

    /**
     * Get token limit for business's plan.
     */
    public function getTokenLimit(): int
    {
        $business = Business::find($this->businessId);
        $plan = $business?->plan;

        if ($plan && isset($plan->features['monthly_tokens'])) {
            return (int) $plan->features['monthly_tokens'];
        }

        $planSlug = $plan?->slug ?? 'free';
        return self::PLAN_LIMITS[$planSlug] ?? self::PLAN_LIMITS['free'];
    }

    /**
     * Get daily usage trend for the current month.
     */
    public function getDailyTrend(): array
    {
        return AiUsageLog::where('business_id', $this->businessId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('
                DATE(created_at) as date,
                SUM(input_tokens + output_tokens) as total_tokens,
                SUM(cost_usd) as total_cost
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date'         => $row->date,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost'   => round($row->total_cost, 4),
            ])
            ->toArray();
    }
}
