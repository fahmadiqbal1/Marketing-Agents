<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Log;

/**
 * PII Redactor — detects and masks personally identifiable information
 * in text before it reaches social media or logs.
 *
 * Converted from Python: security/pii_redactor.py
 *
 * Patterns detected:
 *   - Email addresses
 *   - Phone numbers (international + local formats)
 *   - SSN / National ID formats
 *   - Credit/debit card numbers (Luhn-validated)
 *   - CNIC (Pakistani national ID: 12345-1234567-1)
 */
class PiiRedactorService
{
    /**
     * PII pattern definitions: [type, pattern, replacement]
     */
    protected const PATTERNS = [
        // Email
        [
            'email',
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
            '[EMAIL REDACTED]',
        ],
        // Pakistani CNIC: 12345-1234567-1
        [
            'cnic',
            '/\b\d{5}-\d{7}-\d\b/',
            '[CNIC REDACTED]',
        ],
        // US SSN: 123-45-6789
        [
            'ssn',
            '/\b\d{3}-\d{2}-\d{4}\b/',
            '[SSN REDACTED]',
        ],
        // Credit cards: 13-19 digits with optional dashes/spaces
        [
            'card',
            '/\b(?:\d[ -]?){13,19}\b/',
            '[CARD REDACTED]',
        ],
        // Phone numbers — international variants
        [
            'phone',
            '/(?<!\d)(?:\+?\d{1,3}[\s.\-]?)?(?:\(?\d{2,4}\)?[\s.\-]?)?\d{3,4}[\s.\-]?\d{3,4}(?!\d)/',
            '[PHONE REDACTED]',
        ],
    ];

    /**
     * Scan text and return all PII matches found.
     *
     * @return array<array{type: string, value: string, start: int, end: int, replacement: string}>
     */
    public function scan(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        $matches = [];
        $seenSpans = [];

        foreach (self::PATTERNS as [$type, $pattern, $replacement]) {
            if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
                foreach ($found[0] as [$value, $start]) {
                    $end = $start + strlen($value);
                    $span = "{$start}-{$end}";

                    // Avoid overlapping matches
                    $overlaps = false;
                    foreach ($seenSpans as $seen) {
                        [$s, $e] = explode('-', $seen);
                        if (($start >= $s && $start < $e) || ($end > $s && $end <= $e)) {
                            $overlaps = true;
                            break;
                        }
                    }
                    if ($overlaps) {
                        continue;
                    }

                    // Validate phone length
                    if ($type === 'phone') {
                        $digitsOnly = preg_replace('/\D/', '', $value);
                        if (strlen($digitsOnly) < 7) {
                            continue;
                        }
                    }

                    // Validate card with Luhn algorithm
                    if ($type === 'card') {
                        $digitsOnly = preg_replace('/\D/', '', $value);
                        if (strlen($digitsOnly) < 13 || !$this->luhnCheck($digitsOnly)) {
                            continue;
                        }
                    }

                    $seenSpans[] = $span;
                    $matches[] = [
                        'type'        => $type,
                        'value'       => $value,
                        'start'       => $start,
                        'end'         => $end,
                        'replacement' => $replacement,
                    ];
                }
            }
        }

        // Sort by position
        usort($matches, fn($a, $b) => $a['start'] <=> $b['start']);

        return $matches;
    }

    /**
     * Replace all PII in text with placeholder tokens.
     */
    public function redact(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        $findings = $this->scan($text);
        if (empty($findings)) {
            return $text;
        }

        // Replace from end to start to preserve positions
        $result = $text;
        foreach (array_reverse($findings) as $match) {
            $result = substr($result, 0, $match['start'])
                    . $match['replacement']
                    . substr($result, $match['end']);

            Log::info('PII redacted', [
                'type'            => $match['type'],
                'original_length' => strlen($match['value']),
            ]);
        }

        return $result;
    }

    /**
     * Check if text contains any PII.
     */
    public function hasPii(string $text): bool
    {
        return !empty($this->scan($text));
    }

    /**
     * Luhn algorithm to validate credit card numbers.
     */
    protected function luhnCheck(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Get summary of PII types found.
     */
    public function getSummary(string $text): array
    {
        $findings = $this->scan($text);
        $summary = [];

        foreach ($findings as $match) {
            $type = $match['type'];
            $summary[$type] = ($summary[$type] ?? 0) + 1;
        }

        return [
            'has_pii'     => !empty($findings),
            'total_found' => count($findings),
            'by_type'     => $summary,
        ];
    }
}
