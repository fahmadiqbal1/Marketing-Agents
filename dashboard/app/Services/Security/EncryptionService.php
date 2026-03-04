<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

/**
 * Credential encryption utility — AES-256 encryption for tokens at rest.
 *
 * Converted from Python: security/encryption.py
 *
 * Uses Laravel's built-in encryption which uses APP_KEY as the master key.
 * For tokens stored in the database (OAuth tokens, API keys), this provides
 * an additional layer of encryption beyond MySQL at-rest encryption.
 */
class EncryptionService
{
    /**
     * Encrypt a string using Laravel's AES-256-CBC encryption.
     */
    public function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        try {
            return Crypt::encryptString($plaintext);
        } catch (\Exception $e) {
            Log::error('Encryption failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Decrypt a ciphertext back to plaintext.
     */
    public function decrypt(string $ciphertext): string
    {
        if (empty($ciphertext)) {
            return '';
        }

        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Decryption failed — wrong APP_KEY?', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Safely encrypt an array of credentials.
     *
     * @param array<string, string> $credentials
     * @return array<string, string>
     */
    public function encryptCredentials(array $credentials): array
    {
        $encrypted = [];

        foreach ($credentials as $key => $value) {
            if (!empty($value)) {
                $encrypted[$key] = $this->encrypt($value);
            }
        }

        return $encrypted;
    }

    /**
     * Safely decrypt an array of credentials.
     *
     * @param array<string, string> $encrypted
     * @return array<string, string>
     */
    public function decryptCredentials(array $encrypted): array
    {
        $decrypted = [];

        foreach ($encrypted as $key => $value) {
            if (!empty($value)) {
                $decrypted[$key] = $this->decrypt($value);
            }
        }

        return $decrypted;
    }

    /**
     * Hash a value for comparison (one-way).
     */
    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Verify a value against a hash.
     */
    public function verifyHash(string $value, string $hash): bool
    {
        return hash_equals($hash, $this->hash($value));
    }

    /**
     * Generate a secure random token.
     */
    public function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a secure API key with prefix.
     */
    public function generateApiKey(string $prefix = 'sk'): string
    {
        return $prefix . '_' . $this->generateToken(24);
    }

    /**
     * Mask a secret for display (show first/last 4 chars).
     */
    public function maskSecret(string $secret, int $showChars = 4): string
    {
        $length = strlen($secret);

        if ($length <= $showChars * 2) {
            return str_repeat('*', $length);
        }

        return substr($secret, 0, $showChars)
             . str_repeat('*', $length - ($showChars * 2))
             . substr($secret, -$showChars);
    }

    /**
     * Check if encryption is properly configured.
     */
    public function isConfigured(): bool
    {
        $appKey = config('app.key');

        if (empty($appKey)) {
            Log::critical('APP_KEY not set! Run php artisan key:generate');
            return false;
        }

        // Test encryption/decryption
        try {
            $test = 'encryption_test_' . time();
            $encrypted = $this->encrypt($test);
            $decrypted = $this->decrypt($encrypted);
            return $decrypted === $test;
        } catch (\Exception $e) {
            return false;
        }
    }
}
