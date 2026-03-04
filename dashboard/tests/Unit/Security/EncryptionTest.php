<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Services\Security\EncryptionService;

class EncryptionTest extends TestCase
{
    protected EncryptionService $encryption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->encryption = new EncryptionService();
    }

    public function test_encrypts_and_decrypts_string(): void
    {
        $plaintext = 'my_secret_api_key_12345';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted);

        $this->assertNotEquals($plaintext, $encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function test_handles_empty_string(): void
    {
        $this->assertEquals('', $this->encryption->encrypt(''));
        $this->assertEquals('', $this->encryption->decrypt(''));
    }

    public function test_encrypt_credentials_array(): void
    {
        $credentials = [
            'access_token'  => 'token_abc123',
            'refresh_token' => 'refresh_xyz789',
            'client_secret' => 'secret_key',
        ];

        $encrypted = $this->encryption->encryptCredentials($credentials);
        $decrypted = $this->encryption->decryptCredentials($encrypted);

        $this->assertNotEquals($credentials['access_token'], $encrypted['access_token']);
        $this->assertEquals($credentials, $decrypted);
    }

    public function test_hash_produces_consistent_output(): void
    {
        $value = 'test_value';
        $hash1 = $this->encryption->hash($value);
        $hash2 = $this->encryption->hash($value);

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 produces 64 hex chars
    }

    public function test_verify_hash_works(): void
    {
        $value = 'my_password';
        $hash = $this->encryption->hash($value);

        $this->assertTrue($this->encryption->verifyHash($value, $hash));
        $this->assertFalse($this->encryption->verifyHash('wrong_password', $hash));
    }

    public function test_generate_token(): void
    {
        $token1 = $this->encryption->generateToken();
        $token2 = $this->encryption->generateToken();

        $this->assertEquals(64, strlen($token1)); // 32 bytes = 64 hex chars
        $this->assertNotEquals($token1, $token2);
    }

    public function test_generate_api_key(): void
    {
        $key = $this->encryption->generateApiKey('sk');

        $this->assertStringStartsWith('sk_', $key);
        $this->assertEquals(51, strlen($key)); // 'sk_' + 48 hex chars
    }

    public function test_mask_secret(): void
    {
        $secret = 'my_very_secret_api_key_12345';
        $masked = $this->encryption->maskSecret($secret);

        $this->assertStringStartsWith('my_v', $masked);
        $this->assertStringEndsWith('2345', $masked);
        $this->assertStringContainsString('****', $masked);
    }

    public function test_mask_short_secret(): void
    {
        $secret = 'short';
        $masked = $this->encryption->maskSecret($secret);

        // Short secrets should be fully masked
        $this->assertEquals('*****', $masked);
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->encryption->isConfigured());
    }
}
