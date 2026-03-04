<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Services\Security\PiiRedactorService;

class PiiRedactorTest extends TestCase
{
    protected PiiRedactorService $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new PiiRedactorService();
    }

    public function test_detects_email_addresses(): void
    {
        $text = 'Contact me at john.doe@example.com for more info';
        $result = $this->redactor->scan($text);

        $this->assertCount(1, $result);
        $this->assertEquals('email', $result[0]['type']);
        $this->assertEquals('john.doe@example.com', $result[0]['value']);
    }

    public function test_detects_phone_numbers(): void
    {
        $text = 'Call us at +1-555-123-4567 today!';
        $result = $this->redactor->scan($text);

        $this->assertCount(1, $result);
        $this->assertEquals('phone', $result[0]['type']);
    }

    public function test_detects_pakistani_cnic(): void
    {
        $text = 'My CNIC is 12345-1234567-1';
        $result = $this->redactor->scan($text);

        $this->assertCount(1, $result);
        $this->assertEquals('cnic', $result[0]['type']);
    }

    public function test_detects_us_ssn(): void
    {
        $text = 'SSN: 123-45-6789';
        $result = $this->redactor->scan($text);

        $this->assertCount(1, $result);
        $this->assertEquals('ssn', $result[0]['type']);
    }

    public function test_redacts_all_pii(): void
    {
        $text = 'Email me at john@test.com or call 555-123-4567';
        $redacted = $this->redactor->redact($text);

        $this->assertStringContainsString('[EMAIL REDACTED]', $redacted);
        $this->assertStringContainsString('[PHONE REDACTED]', $redacted);
        $this->assertStringNotContainsString('john@test.com', $redacted);
    }

    public function test_has_pii_returns_true_when_pii_present(): void
    {
        $this->assertTrue($this->redactor->hasPii('Contact john@example.com'));
        $this->assertFalse($this->redactor->hasPii('No personal info here'));
    }

    public function test_handles_empty_string(): void
    {
        $this->assertEquals([], $this->redactor->scan(''));
        $this->assertEquals('', $this->redactor->redact(''));
        $this->assertFalse($this->redactor->hasPii(''));
    }

    public function test_validates_credit_card_with_luhn(): void
    {
        // Valid test card number (passes Luhn)
        $text = 'Card: 4532015112830366';
        $result = $this->redactor->scan($text);

        $this->assertCount(1, $result);
        $this->assertEquals('card', $result[0]['type']);
    }

    public function test_get_summary(): void
    {
        $text = 'Email: test@example.com, Phone: 555-123-4567';
        $summary = $this->redactor->getSummary($text);

        $this->assertTrue($summary['has_pii']);
        $this->assertEquals(2, $summary['total_found']);
        $this->assertArrayHasKey('email', $summary['by_type']);
        $this->assertArrayHasKey('phone', $summary['by_type']);
    }
}
