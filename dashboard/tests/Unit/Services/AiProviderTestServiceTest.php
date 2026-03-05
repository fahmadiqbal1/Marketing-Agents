<?php

namespace Tests\Unit\Services;

use App\Services\AiProviderTestService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderTestServiceTest extends TestCase
{
    protected AiProviderTestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiProviderTestService();
    }

    public function test_unknown_provider_returns_error(): void
    {
        $result = $this->service->test('unknown_provider', 'some-key');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown provider', $result['message']);
    }

    public function test_openai_with_valid_key(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4'], ['id' => 'gpt-3.5-turbo']],
            ], 200),
        ]);

        $result = $this->service->test('openai', 'sk-test-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('OpenAI connected', $result['message']);
    }

    public function test_openai_with_invalid_key(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'error' => ['message' => 'Incorrect API key provided'],
            ], 401),
        ]);

        $result = $this->service->test('openai', 'sk-bad-key');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Incorrect API key', $result['message']);
    }

    public function test_google_gemini_with_valid_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'models' => [['name' => 'gemini-pro'], ['name' => 'gemini-flash']],
            ], 200),
        ]);

        $result = $this->service->test('google_gemini', 'test-api-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Google Gemini connected', $result['message']);
    }

    public function test_google_gemini_with_invalid_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'API key not valid'],
            ], 403),
        ]);

        $result = $this->service->test('google_gemini', 'bad-key');
        $this->assertFalse($result['success']);
    }

    public function test_anthropic_with_valid_key(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => 'Hi']],
            ], 200),
        ]);

        $result = $this->service->test('anthropic', 'test-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Anthropic', $result['message']);
    }

    public function test_anthropic_with_invalid_key(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $result = $this->service->test('anthropic', 'bad-key');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid API key', $result['message']);
    }

    public function test_anthropic_with_bad_model_still_validates_key(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'model not found'],
            ], 400),
        ]);

        $result = $this->service->test('anthropic', 'valid-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('verified', $result['message']);
    }

    public function test_mistral_with_valid_key(): void
    {
        Http::fake([
            'api.mistral.ai/v1/models' => Http::response([
                'data' => [['id' => 'mistral-large']],
            ], 200),
        ]);

        $result = $this->service->test('mistral', 'test-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Mistral', $result['message']);
    }

    public function test_deepseek_with_valid_key(): void
    {
        Http::fake([
            'api.deepseek.com/models' => Http::response([
                'data' => [['id' => 'deepseek-chat']],
            ], 200),
        ]);

        $result = $this->service->test('deepseek', 'test-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('DeepSeek connected', $result['message']);
    }

    public function test_groq_with_valid_key(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response([
                'data' => [['id' => 'llama-3.1-70b']],
            ], 200),
        ]);

        $result = $this->service->test('groq', 'test-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Groq connected', $result['message']);
    }

    public function test_ollama_reachable(): void
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response([
                'models' => [['name' => 'llama3']],
            ], 200),
        ]);

        $result = $this->service->test('ollama', 'local', null, 'http://localhost:11434');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Ollama connected', $result['message']);
    }

    public function test_openai_compatible_needs_base_url(): void
    {
        $result = $this->service->test('openai_compatible', 'key', null, null);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Base URL is required', $result['message']);
    }

    public function test_openai_compatible_with_valid_endpoint(): void
    {
        Http::fake([
            'my-llm.example.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $result = $this->service->test('openai_compatible', 'key', null, 'https://my-llm.example.com');
        $this->assertTrue($result['success']);
    }

    public function test_rate_limited_key_treated_as_valid(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'error' => ['message' => 'Rate limit reached'],
            ], 429),
        ]);

        $result = $this->service->test('openai', 'sk-valid-key');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('rate limited', $result['message']);
    }
}
