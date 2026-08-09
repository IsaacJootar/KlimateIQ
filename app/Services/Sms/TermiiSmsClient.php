<?php

namespace App\Services\Sms;

use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin client over Termii's SMS API — chosen over Twilio for a Nigeria-focused deployment
 * (local delivery routes, no international SMS pricing). Deliberately small, mirroring
 * OpenAiClient: one method, one endpoint.
 */
class TermiiSmsClient implements SmsClient
{
    use ResolvesCaBundle;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $senderId,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.termii.api_key'),
            senderId: (string) config('services.termii.sender_id', 'KlimateIQ'),
            baseUrl: rtrim((string) config('services.termii.base_url', 'https://api.ng.termii.com'), '/'),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function send(string $to, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The Termii API key is not configured.');
        }

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(15)
            ->post($this->baseUrl.'/api/sms/send', [
                'api_key' => $this->apiKey,
                'to' => $to,
                'from' => $this->senderId,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Termii API request failed with status '.$response->status().'.');
        }
    }
}
