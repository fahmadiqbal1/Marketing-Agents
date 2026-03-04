<?php

namespace App\Services;

use App\Models\TelegramBot;
use App\Models\MediaItem;
use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Telegram Bot Service — handles incoming Telegram webhook and media collection.
 * Converted from Python: bot/telegram_bot.py
 *
 * Architecture: Webhook-based (not polling)
 * Each business has its own bot token (multi-tenant).
 *
 * Flow:
 * 1. Webhook receives update from Telegram
 * 2. Service processes message/photo/video
 * 3. Media is downloaded and stored
 * 4. AI analysis is triggered
 * 5. Response sent back to user
 */
class TelegramBotService
{
    private ?TelegramBot $bot = null;
    private ?OpenAIService $openai = null;

    // Telegram API base URL
    private const API_BASE = 'https://api.telegram.org/bot';

    // Supported media types
    private const SUPPORTED_MEDIA = ['photo', 'video', 'document', 'animation'];

    // Command handlers
    private const COMMANDS = [
        '/start'    => 'handleStart',
        '/help'     => 'handleHelp',
        '/status'   => 'handleStatus',
        '/analyze'  => 'handleAnalyze',
        '/caption'  => 'handleCaption',
        '/hashtags' => 'handleHashtags',
    ];

    public function __construct(?TelegramBot $bot = null)
    {
        $this->bot = $bot;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WEBHOOK PROCESSING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process incoming webhook update from Telegram.
     */
    public function processWebhook(array $update, TelegramBot $bot): array
    {
        $this->bot = $bot;

        // Update last activity
        $bot->update(['last_activity_at' => now()]);

        // Determine update type
        if (isset($update['message'])) {
            return $this->processMessage($update['message']);
        }

        if (isset($update['callback_query'])) {
            return $this->processCallback($update['callback_query']);
        }

        return ['success' => true, 'action' => 'ignored'];
    }

    /**
     * Process a message update.
     */
    private function processMessage(array $message): array
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';

        // Handle commands
        if (str_starts_with($text, '/')) {
            $command = explode(' ', $text)[0];
            if (isset(self::COMMANDS[$command])) {
                $method = self::COMMANDS[$command];
                return $this->$method($chatId, $message);
            }
        }

        // Handle media
        foreach (self::SUPPORTED_MEDIA as $mediaType) {
            if (isset($message[$mediaType])) {
                return $this->handleMedia($chatId, $message, $mediaType);
            }
        }

        // Handle text (could be caption request)
        if ($text) {
            return $this->handleText($chatId, $message);
        }

        return ['success' => true, 'action' => 'no_handler'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // COMMAND HANDLERS
    // ═══════════════════════════════════════════════════════════════════════

    private function handleStart(int $chatId, array $message): array
    {
        $name = $message['from']['first_name'] ?? 'there';

        $welcome = "👋 Hi {$name}!\n\n";
        $welcome .= "I'm your marketing assistant bot. Send me photos or videos and I'll:\n\n";
        $welcome .= "📸 Store them in your media library\n";
        $welcome .= "🔍 Analyze content and suggest categories\n";
        $welcome .= "✍️ Generate captions and hashtags\n";
        $welcome .= "📅 Help schedule posts\n\n";
        $welcome .= "Just send any media to get started!";

        $this->sendMessage($chatId, $welcome);

        return ['success' => true, 'action' => 'start'];
    }

    private function handleHelp(int $chatId, array $message): array
    {
        $help = "📖 *Available Commands*\n\n";
        $help .= "/start — Welcome message\n";
        $help .= "/help — Show this help\n";
        $help .= "/status — Check your media library status\n";
        $help .= "/analyze — Analyze the last uploaded media\n";
        $help .= "/caption — Generate caption for last media\n";
        $help .= "/hashtags — Get hashtag suggestions\n\n";
        $help .= "💡 *Tips*\n";
        $help .= "• Send photos/videos directly for processing\n";
        $help .= "• Add a caption when sending for context\n";
        $help .= "• Reply to media with /caption for quick captions";

        $this->sendMessage($chatId, $help, ['parse_mode' => 'Markdown']);

        return ['success' => true, 'action' => 'help'];
    }

    private function handleStatus(int $chatId, array $message): array
    {
        $business = $this->bot->business;

        $totalMedia = MediaItem::where('business_id', $business->id)->count();
        $pendingMedia = MediaItem::where('business_id', $business->id)
            ->where('status', 'new')
            ->count();

        $status = "📊 *Media Library Status*\n\n";
        $status .= "Total items: {$totalMedia}\n";
        $status .= "Pending analysis: {$pendingMedia}\n";

        $this->sendMessage($chatId, $status, ['parse_mode' => 'Markdown']);

        return ['success' => true, 'action' => 'status'];
    }

    private function handleAnalyze(int $chatId, array $message): array
    {
        $lastMedia = MediaItem::where('business_id', $this->bot->business_id)
            ->latest()
            ->first();

        if (!$lastMedia) {
            $this->sendMessage($chatId, "No media found. Send a photo or video first!");
            return ['success' => true, 'action' => 'no_media'];
        }

        $this->sendMessage($chatId, "🔍 Analyzing your last upload...");

        // Run vision analysis
        $vision = new VisionAnalyzerService($this->bot->business_id);
        $result = $vision->detectCategory($lastMedia->file_path);

        if ($result['success']) {
            $response = "📸 *Analysis Complete*\n\n";
            $response .= "Category: {$result['category']}\n";
            $response .= "Confidence: " . round($result['confidence'] * 100) . "%";

            $lastMedia->update([
                'category' => $result['category'],
                'analysis' => $result,
            ]);
        } else {
            $response = "Analysis unavailable. Try again later.";
        }

        $this->sendMessage($chatId, $response, ['parse_mode' => 'Markdown']);

        return ['success' => true, 'action' => 'analyze'];
    }

    private function handleCaption(int $chatId, array $message): array
    {
        $lastMedia = MediaItem::where('business_id', $this->bot->business_id)
            ->latest()
            ->first();

        if (!$lastMedia) {
            $this->sendMessage($chatId, "No media found. Send a photo or video first!");
            return ['success' => true, 'action' => 'no_media'];
        }

        $this->sendMessage($chatId, "✍️ Generating caption...");

        $captionWriter = new CaptionWriterService($this->bot->business_id);
        $result = $captionWriter->generateCaption(
            $lastMedia->category ?? 'general',
            $lastMedia->description ?? '',
            'instagram'
        );

        if ($result['success']) {
            $this->sendMessage($chatId, "📝 *Caption:*\n\n{$result['caption']}", ['parse_mode' => 'Markdown']);
        } else {
            $this->sendMessage($chatId, "Caption generation unavailable.");
        }

        return ['success' => true, 'action' => 'caption'];
    }

    private function handleHashtags(int $chatId, array $message): array
    {
        $lastMedia = MediaItem::where('business_id', $this->bot->business_id)
            ->latest()
            ->first();

        $category = $lastMedia?->category ?? 'general';

        $hashtagService = new HashtagResearcherService($this->bot->business_id);
        $hashtags = $hashtagService->getHashtags($category, 'instagram');

        $response = "🏷️ *Suggested Hashtags*\n\n{$hashtags}";

        $this->sendMessage($chatId, $response, ['parse_mode' => 'Markdown']);

        return ['success' => true, 'action' => 'hashtags'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MEDIA HANDLING
    // ═══════════════════════════════════════════════════════════════════════

    private function handleMedia(int $chatId, array $message, string $mediaType): array
    {
        $this->sendMessage($chatId, "📥 Received! Processing...");

        // Get file ID
        $fileId = $this->extractFileId($message, $mediaType);

        if (!$fileId) {
            $this->sendMessage($chatId, "Could not process this media. Try a different file.");
            return ['success' => false, 'error' => 'no_file_id'];
        }

        // Download file
        $filePath = $this->downloadFile($fileId);

        if (!$filePath) {
            $this->sendMessage($chatId, "Download failed. Please try again.");
            return ['success' => false, 'error' => 'download_failed'];
        }

        // Store in database
        $caption = $message['caption'] ?? null;

        $mediaItem = MediaItem::create([
            'business_id'  => $this->bot->business_id,
            'telegram_file_id' => $fileId,
            'media_type'   => $mediaType === 'photo' ? 'image' : $mediaType,
            'file_path'    => $filePath,
            'caption'      => $caption,
            'status'       => 'new',
            'metadata'     => [
                'from'       => $message['from'] ?? null,
                'message_id' => $message['message_id'] ?? null,
            ],
        ]);

        // Send confirmation
        $response = "✅ *Media saved!*\n\n";
        $response .= "Type: " . ucfirst($mediaType) . "\n";
        $response .= "ID: #{$mediaItem->id}\n\n";
        $response .= "Commands:\n";
        $response .= "/analyze — Detect category\n";
        $response .= "/caption — Generate caption\n";
        $response .= "/hashtags — Get hashtags";

        $this->sendMessage($chatId, $response, ['parse_mode' => 'Markdown']);

        return ['success' => true, 'action' => 'media_saved', 'media_id' => $mediaItem->id];
    }

    private function handleText(int $chatId, array $message): array
    {
        $text = $message['text'] ?? '';

        // Simple response for now
        $response = "💬 Got your message! Send a photo or video to get started, or use /help for commands.";

        $this->sendMessage($chatId, $response);

        return ['success' => true, 'action' => 'text_response'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CALLBACK HANDLERS
    // ═══════════════════════════════════════════════════════════════════════

    private function processCallback(array $callback): array
    {
        $chatId = $callback['message']['chat']['id'] ?? null;
        $data = $callback['data'] ?? '';

        if (!$chatId) {
            return ['success' => false, 'error' => 'no_chat_id'];
        }

        // Answer callback query
        $this->answerCallback($callback['id']);

        // Handle specific callbacks
        // e.g., approve_123, reject_123, etc.

        return ['success' => true, 'action' => 'callback', 'data' => $data];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TELEGRAM API METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Send a text message.
     */
    public function sendMessage(int $chatId, string $text, array $options = []): bool
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $options);

        $response = $this->request('sendMessage', $params);

        return $response['ok'] ?? false;
    }

    /**
     * Send a photo.
     */
    public function sendPhoto(int $chatId, string $photo, ?string $caption = null): bool
    {
        $params = [
            'chat_id' => $chatId,
            'photo'   => $photo,
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        $response = $this->request('sendPhoto', $params);

        return $response['ok'] ?? false;
    }

    /**
     * Answer callback query.
     */
    public function answerCallback(string $callbackId, ?string $text = null): bool
    {
        $params = ['callback_query_id' => $callbackId];

        if ($text) {
            $params['text'] = $text;
        }

        $response = $this->request('answerCallbackQuery', $params);

        return $response['ok'] ?? false;
    }

    /**
     * Get file info.
     */
    public function getFile(string $fileId): ?array
    {
        $response = $this->request('getFile', ['file_id' => $fileId]);

        return $response['ok'] ? $response['result'] : null;
    }

    /**
     * Download file from Telegram.
     */
    public function downloadFile(string $fileId): ?string
    {
        $fileInfo = $this->getFile($fileId);

        if (!$fileInfo || !isset($fileInfo['file_path'])) {
            return null;
        }

        $telegramPath = $fileInfo['file_path'];
        $url = "https://api.telegram.org/file/bot{$this->bot->bot_token}/{$telegramPath}";

        try {
            $content = Http::get($url)->body();

            // Generate local path
            $extension = pathinfo($telegramPath, PATHINFO_EXTENSION);
            $localPath = "telegram/{$this->bot->business_id}/" . uniqid() . ".{$extension}";

            Storage::disk('local')->put($localPath, $content);

            return $localPath;

        } catch (\Exception $e) {
            Log::error("Telegram file download failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set webhook URL.
     */
    public function setWebhook(string $url): bool
    {
        $response = $this->request('setWebhook', ['url' => $url]);

        if ($response['ok'] ?? false) {
            $this->bot->update(['webhook_url' => $url, 'is_active' => true]);
            return true;
        }

        return false;
    }

    /**
     * Delete webhook.
     */
    public function deleteWebhook(): bool
    {
        $response = $this->request('deleteWebhook', []);

        if ($response['ok'] ?? false) {
            $this->bot->update(['webhook_url' => null, 'is_active' => false]);
            return true;
        }

        return false;
    }

    /**
     * Get bot info.
     */
    public function getMe(): ?array
    {
        $response = $this->request('getMe', []);

        return $response['ok'] ? $response['result'] : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function request(string $method, array $params): array
    {
        if (!$this->bot || !$this->bot->bot_token) {
            return ['ok' => false, 'error' => 'No bot token'];
        }

        $url = self::API_BASE . $this->bot->bot_token . '/' . $method;

        try {
            $response = Http::post($url, $params)->json();
            return $response ?? ['ok' => false];
        } catch (\Exception $e) {
            Log::error("Telegram API error: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function extractFileId(array $message, string $mediaType): ?string
    {
        if ($mediaType === 'photo') {
            // Photos come as array of sizes, get largest
            $photos = $message['photo'] ?? [];
            $largest = end($photos);
            return $largest['file_id'] ?? null;
        }

        return $message[$mediaType]['file_id'] ?? null;
    }
}
