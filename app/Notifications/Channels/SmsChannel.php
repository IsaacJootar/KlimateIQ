<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsClient;
use Illuminate\Notifications\Notification;

/**
 * A Laravel notification channel over SmsClient. Returning SmsChannel::class from a
 * notification's via() is enough — no registration needed, the container resolves it.
 */
class SmsChannel
{
    public function __construct(private readonly SmsClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $phone = $notifiable->phone_number ?? null;

        if (! $phone || ! $this->client->isConfigured()) {
            return;
        }

        $this->client->send($phone, $notification->toSms($notifiable));
    }
}
