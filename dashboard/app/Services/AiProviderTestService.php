<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Provider Test Service — validates API keys by making real API calls
 * to each supported AI provider.
 */
class AiProviderTestService
{
    /**
     * Test an AI provider's API key by making a lightweight API call.
     *
     * @return array{success: bool, message: string}
     */
    public function test(string $provider, string $apiKey, ?string $modelName = null, ?string $baseUrl = null): array
    {
        try {
            return match ($provider) {
                'openai'             => $this->testOpenAI($apiKey),
                'google_gemini'      => $this->testGoogleGemini($apiKey),
                'anthropic'          => $this->testAnthropic($apiKey),
                'mistral'            => $this->testMistral($apiKey),
                'deepseek'           => $this->testDeepSeek($apiKey),
                'groq'               => $this->testGroq($apiKey),
                'ollama'             => $this->testOllama($baseUrl),
                'openai_compatible'  => $this->testOpenAICompatible($apiKey, $baseUrl),
                default              => ['success' => false, 'message' => "Unknown provider: {$provider}"],
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning("AI provider test connection failed for {$provider}", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Connection failed: could not reach the API server. Check your network or base URL.'];
        } catch (\Exception $e) {
            Log::warning("AI provider test failed for {$provider}", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Test failed: ' . $e->getMessage()];
        }
    }

    protected function testOpenAI(string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(15)->get('https://api.openai.com/v1/models');

        if ($response->successful()) {
            $models = $response->json('data', []);
            $count = count($models);
            return ['success' => true, 'message' => "OpenAI connected — {$count} models available."];
        }

        return $this->parseError('OpenAI', $response);
    }

    protected function testGoogleGemini(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->get("https://generativelanguage.googleapis.com/v1beta/models", [
                'key' => $apiKey,
            ]);

        if ($response->successful()) {
            $models = $response->json('models', []);
            $count = count($models);
            return ['success' => true, 'message' => "Google Gemini connected — {$count} models available."];
        }

        return $this->parseError('Google Gemini', $response);
    }

    protected function testAnthropic(string $apiKey): array
    {
        // Anthropic doesn't have a /models list endpoint, so we send a minimal message
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 10,
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Anthropic Claude connected and verified.'];
        }

        // 401 means bad key, but other errors (e.g. 400 model not found) still confirm key works
        $status = $response->status();
        if ($status === 401) {
            return ['success' => false, 'message' => 'Anthropic: Invalid API key.'];
        }
        if ($status === 400 || $status === 404) {
            // Bad model or similar — key itself is valid
            return ['success' => true, 'message' => 'Anthropic key verified (authenticated successfully).'];
        }

        return $this->parseError('Anthropic', $response);
    }

    protected function testMistral(string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(15)->get('https://api.mistral.ai/v1/models');

        if ($response->successful()) {
            $models = $response->json('data', []);
            $count = count($models);
            return ['success' => true, 'message' => "Mistral AI connected — {$count} models available."];
        }

        return $this->parseError('Mistral', $response);
    }

    protected function testDeepSeek(string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(15)->get('https://api.deepseek.com/models');

        if ($response->successful()) {
            $models = $response->json('data', []);
            $count = count($models);
            return ['success' => true, 'message' => "DeepSeek connected — {$count} models available."];
        }

        return $this->parseError('DeepSeek', $response);
    }

    protected function testGroq(string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(15)->get('https://api.groq.com/openai/v1/models');

        if ($response->successful()) {
            $models = $response->json('data', []);
            $count = count($models);
            return ['success' => true, 'message' => "Groq connected — {$count} models available."];
        }

        return $this->parseError('Groq', $response);
    }

    protected function testOllama(?string $baseUrl): array
    {
        $url = rtrim($baseUrl ?: 'http://localhost:11434', '/');
        $response = Http::timeout(10)->get("{$url}/api/tags");

        if ($response->successful()) {
            $models = $response->json('models', []);
            $count = count($models);
            return ['success' => true, 'message' => "Ollama connected — {$count} local models found."];
        }

        return ['success' => false, 'message' => "Cannot reach Ollama at {$url}. Make sure it is running."];
    }

    protected function testOpenAICompatible(string $apiKey, ?string $baseUrl): array
    {
        if (empty($baseUrl)) {
            return ['success' => false, 'message' => 'Base URL is required for custom/OpenAI-compatible endpoints.'];
        }

        $url = rtrim($baseUrl, '/');
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(15)->get("{$url}/v1/models");

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Custom endpoint connected and responding.'];
        }

        // Try without /v1 prefix
        $response2 = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(10)->get("{$url}/models");

        if ($response2->successful()) {
            return ['success' => true, 'message' => 'Custom endpoint connected and responding.'];
        }

        return ['success' => false, 'message' => "Cannot reach endpoint at {$url}. Status: " . $response->status()];
    }

    /**
     * Parse common error responses.
     *
     * @return array{success: bool, message: string}
     */
    protected function parseError(string $providerName, \Illuminate\Http\Client\Response $response): array
    {
        $status = $response->status();
        $body = $response->json();

        if ($status === 401 || $status === 403) {
            $detail = $body['error']['message'] ?? ($body['message'] ?? 'Invalid or expired API key.');
            return ['success' => false, 'message' => "{$providerName}: {$detail}"];
        }

        if ($status === 429) {
            return ['success' => true, 'message' => "{$providerName} key verified (rate limited — key is valid but quota exceeded)."];
        }

        $detail = $body['error']['message'] ?? ($body['message'] ?? $response->body());
        return ['success' => false, 'message' => "{$providerName} error ({$status}): " . mb_substr($detail, 0, 200)];
    }
}
