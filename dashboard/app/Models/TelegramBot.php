<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram bot configuration per business.
 */
class TelegramBot extends Model
{
    protected $fillable = [
        'business_id',
        'bot_token',
        'bot_username',
        'admin_chat_ids',
        'is_active',
    ];

    protected $casts = [
        'admin_chat_ids' => 'array',
        'is_active'      => 'boolean',
    ];

    protected $hidden = [
        'bot_token', // Never expose token in API responses
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Check if a chat ID is authorized as admin.
     */
    public function isAuthorizedAdmin(int|string $chatId): bool
    {
        $admins = $this->admin_chat_ids ?? [];
        return in_array((string) $chatId, array_map('strval', $admins));
    }
}
