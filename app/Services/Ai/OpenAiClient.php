<?php

namespace App\Services\Ai;

use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin client over the OpenAI (ChatGPT) Chat Completions API.
 *
 * Deliberately small: one method, one endpoint. What to say and how to guard it lives in the
 * services above; this only carries the request.
 */
class OpenAiClient implements AiChatClient
{
    use ResolvesCaBundle;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.openai.api_key'),
            model: (string) config('services.openai.model', 'gpt-4o'),
            baseUrl: rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/'),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function message(string $system, string $user, int $maxTokens = 1024): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The OpenAI API key is not configured.');
        }

        $response = Http::withToken($this->apiKey)
            ->withOptions(['verify' => $this->explicitCaBundle() ?? $this->caBundle()])
            ->timeout(30)
            ->post($this->baseUrl.'/v1/chat/completions', [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI API request failed with status '.$response->status().'.');
        }

        $text = (string) $response->json('choices.0.message.content', '');

        if (trim($text) === '') {
            throw new RuntimeException('OpenAI API returned no text.');
        }

        return trim($text);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * An explicitly configured CA bundle path takes precedence over the shared auto-detection
     * in ResolvesCaBundle. Returns null when nothing is explicitly configured.
     */
    private function explicitCaBundle(): ?string
    {
        $configured = config('services.openai.ca_bundle');

        return (is_string($configured) && $configured !== '' && is_file($configured)) ? $configured : null;
    }
}
