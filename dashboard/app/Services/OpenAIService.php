<?php

namespace App\Services;

use App\Models\AiModelConfig;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * OpenAI Service — Base wrapper for OpenAI API calls.
 * Handles API key management, request formatting, and token tracking.
 */
class OpenAIService
{
    protected ?string $apiKey = null;
    protected string $model = 'gpt-4o-mini';
    protected int $businessId;

    public function __construct(int $businessId = 0)
    {
        $this->businessId = $businessId;
        $this->loadApiKey();
    }

    protected function loadApiKey(): void
    {
        if ($this->businessId > 0) {
            $config = AiModelConfig::where('business_id', $this->businessId)
                ->where('provider', 'openai')
                ->where('is_active', true)
                ->first();

            if ($config) {
                $this->apiKey = $config->api_key;
                if ($config->model_name) {
                    $this->model = $config->model_name;
                }
            }
        }

        // Fallback to env
        if (!$this->apiKey) {
            $this->apiKey = config('services.openai.api_key');
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Send a chat completion request to OpenAI.
     */
    public function chatCompletion(
        string $systemPrompt,
        string $userPrompt,
        float $temperature = 0.7,
        int $maxTokens = 1000,
        bool $jsonResponse = true
    ): array {
        if (!$this->apiKey) {
            throw new \RuntimeException('OpenAI API key not configured. Please add your API key in Settings → AI Models.');
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($jsonResponse) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', $payload);

            if (!$response->successful()) {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('OpenAI API request failed: ' . $response->body());
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            // Track usage
            $this->trackUsage($data['usage'] ?? [], 'chat_completion');

            if ($jsonResponse) {
                return json_decode($content, true) ?? ['error' => 'Failed to parse JSON response'];
            }

            return ['content' => $content];
        } catch (\Exception $e) {
            Log::error('OpenAI request failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Track token usage for billing/analytics.
     */
    protected function trackUsage(array $usage, string $operation): void
    {
        if (empty($usage) || $this->businessId <= 0) {
            return;
        }

        try {
            // Check if table exists to avoid errors during initial setup
            if (!Schema::hasTable('ai_usage_logs')) {
                return;
            }

            DB::table('ai_usage_logs')->insert([
                'business_id' => $this->businessId,
                'provider' => 'openai',
                'model' => $this->model,
                'operation' => $operation,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silent fail — don't break the main operation
            Log::warning('Failed to track AI usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Load business context for prompt injection.
     */
    public function loadBusinessContext(): array
    {
        $business = Business::find($this->businessId);

        if (!$business) {
            return $this->defaultContext();
        }

        return [
            'business_name' => $business->name ?? 'Your Business',
            'brand_website_line' => $business->website ? " ({$business->website})" : '',
            'brand_description' => $business->industry ?? '',
            'address' => $business->address ?? 'Visit us',
            'phone' => $business->phone ?? 'Call us',
            'website' => $business->website ?? '',
            'brand_voice' => $business->brand_voice ?? 'Professional, engaging, and authentic',
            'industry' => $business->industry ?? 'general',
        ];
    }

    protected function defaultContext(): array
    {
        return [
            'business_name' => 'Your Business',
            'brand_website_line' => '',
            'brand_description' => '',
            'address' => 'Visit us',
            'phone' => 'Call us',
            'website' => '',
            'brand_voice' => 'Professional, engaging, and authentic',
            'industry' => 'general',
        ];
    }

    /**
     * Sanitize user input for LLM prompt injection protection.
     */
    public static function sanitize(string $input): string
    {
        // Remove potential injection patterns
        $dangerous = [
            'ignore previous instructions',
            'ignore all previous',
            'disregard the above',
            'forget everything',
            'new instructions',
            'you are now',
            'act as',
            'pretend to be',
            'system prompt',
            '```system',
        ];

        $sanitized = $input;
        foreach ($dangerous as $pattern) {
            $sanitized = str_ireplace($pattern, '[filtered]', $sanitized);
        }

        // Limit length
        return mb_substr($sanitized, 0, 5000);
    }
}
