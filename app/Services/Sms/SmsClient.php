<?php

namespace App\Services\Sms;

use RuntimeException;

/**
 * A single-message SMS sender, provider-agnostic — same shape as AiChatClient, so the SMS
 * provider can be swapped without touching the notification channel that calls it.
 */
interface SmsClient
{
    /**
     * Whether the integration is configured (has an API key). When false, callers degrade
     * gracefully — SMS is an optional alert channel, never the only one.
     */
    public function isConfigured(): bool;

    /**
     * @param  string  $to  E.164 or local Nigerian format (the provider normalizes it)
     *
     * @throws RuntimeException on transport or API failure — callers must catch and degrade.
     */
    public function send(string $to, string $message): void;
}
