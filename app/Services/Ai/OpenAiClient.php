<?php

namespace App\Services\Ai;

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
            ->withOptions(['verify' => $this->caBundle()])
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
     * Resolve a CA certificate bundle for TLS verification.
     *
     * On a correctly configured host this returns true (use the system store). On a dev
     * machine whose php.ini leaves curl.cainfo empty — the usual cause of "the AI summary
     * could not be generated" — outbound TLS to the API fails before it leaves the machine.
     * We look for a bundle explicitly configured, set in php.ini, or shipped with a common
     * local PHP/XAMPP install, so the request succeeds without the user editing php.ini.
     *
     * @return string|bool a bundle path, or true for default verification
     */
    private function caBundle(): string|bool
    {
        $configured = config('services.openai.ca_bundle');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        foreach (['curl.cainfo', 'openssl.cafile'] as $directive) {
            $path = ini_get($directive);
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        foreach ([
            'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
            'C:\\xampp\\php\\extras\\ssl\\cacert.pem',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return true;
    }
}
