<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Voiceover Service — TTS audio generation for video narration.
 *
 *
 * Supports:
 * - OpenAI TTS API (primary, high quality)
 * - Azure Cognitive Services TTS (alternative)
 * - Shell out to edge-tts CLI (free, if Python available)
 */
class VoiceoverService
{
    /**
     * Available voices well-suited for professional content.
     */
    protected const VOICES = [
        // OpenAI voices
        'alloy' => ['provider' => 'openai', 'voice' => 'alloy', 'description' => 'Neutral, balanced'],
        'echo' => ['provider' => 'openai', 'voice' => 'echo', 'description' => 'Male, warm'],
        'fable' => ['provider' => 'openai', 'voice' => 'fable', 'description' => 'British male'],
        'onyx' => ['provider' => 'openai', 'voice' => 'onyx', 'description' => 'Deep male'],
        'nova' => ['provider' => 'openai', 'voice' => 'nova', 'description' => 'Female, friendly'],
        'shimmer' => ['provider' => 'openai', 'voice' => 'shimmer', 'description' => 'Female, warm'],

        // Edge-TTS voices (free, requires Python)
        'warm_female' => ['provider' => 'edge', 'voice' => 'en-US-JennyNeural', 'description' => 'Warm, professional'],
        'friendly_female' => ['provider' => 'edge', 'voice' => 'en-US-AriaNeural', 'description' => 'Friendly, clear'],
        'professional_male' => ['provider' => 'edge', 'voice' => 'en-US-GuyNeural', 'description' => 'Deep, authoritative'],
        'calm_female' => ['provider' => 'edge', 'voice' => 'en-GB-SoniaNeural', 'description' => 'British, calming'],
        'energetic_female' => ['provider' => 'edge', 'voice' => 'en-US-SaraNeural', 'description' => 'Upbeat, engaging'],
    ];

    protected const CATEGORY_INTROS = [
        'hydrafacial' => 'Experience the glow! Here\'s a look at our hydrafacial treatments.',
        'laser_hair_removal' => 'Smooth, painless, permanent. Discover our laser hair removal results.',
        'laboratory' => 'Your health, our priority. Inside our state-of-the-art clinical laboratory.',
        'opd' => 'Compassionate care, every visit. See what makes our OPD special.',
        'facility' => 'Modern healthcare meets comfort. Take a tour of our facility.',
        'team' => 'Meet our dedicated team.',
        'before_after' => 'Real results, real customers. See the transformations.',
    ];

    protected ?string $openaiApiKey;
    protected string $outputPath;

    public function __construct()
    {
        $this->openaiApiKey = config('services.openai.api_key');
        $this->outputPath = storage_path('app/media/voiceovers');

        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Generate a voiceover audio file from text.
     *
     * @param string $text The text to convert to speech
     * @param string $voice Voice name (from VOICES constant)
     * @param string $outputName Base name for output file
     * @param float $speed Speech speed (0.25 to 4.0, default 1.0)
     * @return string Path to the generated audio file
     */
    public function generateVoiceover(
        string $text,
        string $voice = 'nova',
        string $outputName = 'voiceover',
        float $speed = 1.0
    ): string {
        $voiceConfig = self::VOICES[$voice] ?? self::VOICES['nova'];

        return match ($voiceConfig['provider']) {
            'openai' => $this->generateWithOpenAI($text, $voiceConfig['voice'], $outputName, $speed),
            'edge' => $this->generateWithEdgeTts($text, $voiceConfig['voice'], $outputName, $speed),
            default => throw new \RuntimeException("Unknown voice provider: {$voiceConfig['provider']}"),
        };
    }

    /**
     * Generate voiceover using OpenAI TTS API.
     */
    protected function generateWithOpenAI(
        string $text,
        string $voice,
        string $outputName,
        float $speed
    ): string {
        if (!$this->openaiApiKey) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $filename = $this->generateFilename($outputName, 'mp3');
        $outputPath = $this->outputPath . '/' . $filename;

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->openaiApiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => 'tts-1',
                    'input' => $text,
                    'voice' => $voice,
                    'speed' => max(0.25, min(4.0, $speed)),
                    'response_format' => 'mp3',
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('OpenAI TTS error: ' . $response->body());
            }

            file_put_contents($outputPath, $response->body());

            Log::info('Generated voiceover with OpenAI', [
                'voice' => $voice,
                'chars' => strlen($text),
                'output' => $filename,
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('OpenAI TTS failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Voice generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate voiceover using edge-tts CLI (requires Python + edge-tts package).
     */
    protected function generateWithEdgeTts(
        string $text,
        string $voice,
        string $outputName,
        float $speed
    ): string {
        $filename = $this->generateFilename($outputName, 'mp3');
        $outputPath = $this->outputPath . '/' . $filename;

        // Calculate rate adjustment
        $ratePercent = (int) (($speed - 1.0) * 100);
        $rateStr = $ratePercent >= 0 ? "+{$ratePercent}%" : "{$ratePercent}%";

        // Escape text for command line
        $escapedText = escapeshellarg($text);

        // Build command
        $command = sprintf(
            'edge-tts --voice %s --rate=%s --text %s --write-media %s 2>&1',
            escapeshellarg($voice),
            escapeshellarg($rateStr),
            $escapedText,
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            Log::warning('edge-tts failed, falling back to OpenAI', [
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
            ]);

            // Fallback to OpenAI if edge-tts fails
            if ($this->openaiApiKey) {
                return $this->generateWithOpenAI($text, 'nova', $outputName, $speed);
            }

            throw new \RuntimeException('edge-tts failed and no fallback available');
        }

        Log::info('Generated voiceover with edge-tts', [
            'voice' => $voice,
            'chars' => strlen($text),
            'output' => $filename,
        ]);

        return $outputPath;
    }

    /**
     * Generate a narration script and audio for a compilation video.
     *
     * @param string $category Content category
     * @param array $itemDescriptions Optional descriptions of items in compilation
     * @return string Path to generated audio file
     */
    public function generateNarrationForCompilation(
        string $category,
        array $itemDescriptions = []
    ): string {
        // Build narration script
        $intro = self::CATEGORY_INTROS[$category]
            ?? 'Discover excellence with our latest work.';

        $outro = 'Contact us today to learn more. Visit our website or call us now.';

        $script = "{$intro} {$outro}";

        return $this->generateVoiceover(
            text: $script,
            voice: 'warm_female',
            outputName: "narration_{$category}",
            speed: 0.95 // Slightly slower for clarity
        );
    }

    /**
     * Generate unique filename for output.
     */
    protected function generateFilename(string $baseName, string $extension): string
    {
        $timestamp = now()->format('Ymd_His');
        $uid = Str::random(6);
        return "vo_{$baseName}_{$timestamp}_{$uid}.{$extension}";
    }

    /**
     * Get list of available voices.
     */
    public function getAvailableVoices(): array
    {
        $voices = [];
        foreach (self::VOICES as $key => $config) {
            $voices[$key] = [
                'name' => $key,
                'provider' => $config['provider'],
                'description' => $config['description'],
                'available' => $config['provider'] === 'openai'
                    ? !empty($this->openaiApiKey)
                    : $this->isEdgeTtsAvailable(),
            ];
        }
        return $voices;
    }

    /**
     * Check if edge-tts CLI is available.
     */
    protected function isEdgeTtsAvailable(): bool
    {
        exec('edge-tts --version 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->openaiApiKey) || $this->isEdgeTtsAvailable();
    }

    /**
     * Get the output directory path.
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }
}
