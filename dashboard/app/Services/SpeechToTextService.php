<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Speech-to-Text Service — transcription for voice notes using OpenAI Whisper API.
 *
 *
 * Features:
 * - Voice note transcription using OpenAI Whisper API
 * - Intent classification for voice commands
 * - Job extraction from voice messages
 * - Multi-language support (auto-detection)
 */
class SpeechToTextService
{
    protected const SUPPORTED_AUDIO_EXTENSIONS = [
        'ogg', 'oga', 'mp3', 'm4a', 'wav', 'webm', 'flac', 'aac', 'mp4'
    ];

    protected const INTENT_CLASSIFICATION_PROMPT = <<<'PROMPT'
You are an intent classifier for a marketing platform. Classify the following transcribed voice message into ONE of these intents:

- **post_media**: User wants to post something, add a caption, or share content on social media
- **create_job**: User wants to create a job posting, hire someone, find a doctor/staff
- **ask_question**: User is asking a question about the platform, how-to, or general inquiry
- **command**: User is giving a specific command (e.g., "show my analytics", "check growth report")
- **schedule**: User wants to schedule a post or set a reminder
- **platform_setup**: User wants to connect or disconnect a social media platform

Respond with ONLY a JSON object:
{
  "intent": "<intent_name>",
  "confidence": <0.0-1.0>,
  "summary": "<1-sentence summary of what the user wants>",
  "extracted_data": {<any structured data you can extract, e.g., job_title, platform, etc.>}
}

Transcribed message:
PROMPT;

    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
    }

    /**
     * Transcribe a voice note file to text using OpenAI Whisper API.
     *
     * @param string $filePath Path to the audio file
     * @param string|null $language Language code (e.g., 'en', 'ur'). null = auto-detect
     * @return array with keys: text, language, duration_seconds
     */
    public function transcribeVoiceNote(string $filePath, ?string $language = null): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_AUDIO_EXTENSIONS)) {
            throw new \RuntimeException(
                "Unsupported audio format: .{$extension}. " .
                "Supported: " . implode(', ', self::SUPPORTED_AUDIO_EXTENSIONS)
            );
        }

        if (!$this->apiKey) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        try {
            $params = [
                'model' => 'whisper-1',
                'response_format' => 'verbose_json',
            ];

            if ($language) {
                $params['language'] = $language;
            }

            $response = Http::timeout(120)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post('https://api.openai.com/v1/audio/transcriptions', $params);

            if (!$response->successful()) {
                throw new \RuntimeException('Whisper API error: ' . $response->body());
            }

            $result = $response->json();

            return [
                'text' => trim($result['text'] ?? ''),
                'language' => $result['language'] ?? 'unknown',
                'duration_seconds' => round($result['duration'] ?? 0, 1),
                'segments' => array_map(function ($s) {
                    return [
                        'start' => $s['start'] ?? 0,
                        'end' => $s['end'] ?? 0,
                        'text' => trim($s['text'] ?? ''),
                    ];
                }, $result['segments'] ?? []),
            ];

        } catch (\Exception $e) {
            Log::error('Voice transcription failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Transcription failed: ' . $e->getMessage());
        }
    }

    /**
     * Classify the intent of a transcribed voice message using GPT-4o-mini.
     *
     * @param string $transcribedText The transcribed text
     * @return array with keys: intent, confidence, summary, extracted_data
     */
    public function classifyVoiceIntent(string $transcribedText): array
    {
        if (empty(trim($transcribedText))) {
            return [
                'intent' => 'unknown',
                'confidence' => 0.0,
                'summary' => 'Empty voice message',
                'extracted_data' => [],
            ];
        }

        if (!$this->apiKey) {
            // Fallback to keyword matching
            return $this->fallbackIntentClassification($transcribedText);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => self::INTENT_CLASSIFICATION_PROMPT],
                        ['role' => 'user', 'content' => $transcribedText],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 300,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (!$response->successful()) {
                return $this->fallbackIntentClassification($transcribedText);
            }

            $result = json_decode(
                $response->json()['choices'][0]['message']['content'] ?? '{}',
                true
            );

            return [
                'intent' => $result['intent'] ?? 'unknown',
                'confidence' => (float) ($result['confidence'] ?? 0.5),
                'summary' => $result['summary'] ?? substr($transcribedText, 0, 100),
                'extracted_data' => $result['extracted_data'] ?? [],
            ];

        } catch (\Exception $e) {
            Log::warning('Intent classification failed', ['error' => $e->getMessage()]);
            return $this->fallbackIntentClassification($transcribedText);
        }
    }

    /**
     * Extract job posting details from a transcribed voice message.
     *
     * @param string $transcribedText The transcribed text
     * @return array with: title, department, experience_required, key_skills, salary_range, notes
     */
    public function extractJobFromVoice(string $transcribedText): array
    {
        if (!$this->apiKey) {
            return [
                'title' => '',
                'department' => 'General',
                'experience_required' => '',
                'key_skills' => [],
                'salary_range' => '',
                'notes' => $transcribedText,
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => <<<'SYSTEM'
Extract job posting details from this voice message transcript. Return a JSON object with these fields:
- "title": job title (e.g., "Dental Surgeon")
- "department": department name (e.g., "OPD", "Laboratory", "Aesthetics")
- "experience_required": years/level (e.g., "5 years", "Fresh graduate")
- "key_skills": array of skills (e.g., ["RCT", "Dental Surgery"])
- "salary_range": salary info or empty string
- "notes": any additional details

If a field is not mentioned, use a sensible default or empty string/array.
Respond with ONLY the JSON object.
SYSTEM
                        ],
                        ['role' => 'user', 'content' => $transcribedText],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 400,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('API error');
            }

            $result = json_decode(
                $response->json()['choices'][0]['message']['content'] ?? '{}',
                true
            );

            return [
                'title' => $result['title'] ?? '',
                'department' => $result['department'] ?? 'General',
                'experience_required' => $result['experience_required'] ?? '',
                'key_skills' => $result['key_skills'] ?? [],
                'salary_range' => $result['salary_range'] ?? '',
                'notes' => $result['notes'] ?? '',
            ];

        } catch (\Exception $e) {
            Log::error('Job extraction from voice failed', ['error' => $e->getMessage()]);
            return [
                'title' => '',
                'department' => 'General',
                'experience_required' => '',
                'key_skills' => [],
                'salary_range' => '',
                'notes' => $transcribedText,
            ];
        }
    }

    /**
     * Fallback intent classification using keyword matching.
     */
    protected function fallbackIntentClassification(string $text): array
    {
        $textLower = strtolower($text);

        if ($this->containsAny($textLower, ['hire', 'job', 'doctor', 'nurse', 'staff', 'position', 'vacancy'])) {
            return [
                'intent' => 'create_job',
                'confidence' => 0.6,
                'summary' => substr($text, 0, 100),
                'extracted_data' => [],
            ];
        }

        if ($this->containsAny($textLower, ['post', 'share', 'upload', 'publish'])) {
            return [
                'intent' => 'post_media',
                'confidence' => 0.6,
                'summary' => substr($text, 0, 100),
                'extracted_data' => [],
            ];
        }

        if ($this->containsAny($textLower, ['schedule', 'tomorrow', 'later', 'time'])) {
            return [
                'intent' => 'schedule',
                'confidence' => 0.6,
                'summary' => substr($text, 0, 100),
                'extracted_data' => [],
            ];
        }

        if ($this->containsAny($textLower, ['connect', 'setup', 'instagram', 'facebook', 'tiktok', 'linkedin'])) {
            return [
                'intent' => 'platform_setup',
                'confidence' => 0.6,
                'summary' => substr($text, 0, 100),
                'extracted_data' => [],
            ];
        }

        return [
            'intent' => 'ask_question',
            'confidence' => 0.4,
            'summary' => substr($text, 0, 100),
            'extracted_data' => [],
        ];
    }

    /**
     * Check if text contains any of the given keywords.
     */
    protected function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}
