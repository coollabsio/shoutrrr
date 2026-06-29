<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class ProviderModelCatalog
{
    /** Providers that support model listing */
    public const array LISTABLE = [
        'anthropic', 'openai', 'gemini', 'ollama', 'mistral', 'groq', 'xai', 'deepseek', 'openrouter',
    ];

    /** @return list<string> */
    public function models(string $provider, ?string $apiKey = null): array
    {
        if (! in_array($provider, self::LISTABLE, true)) {
            return [];
        }

        $cached = Cache::get("ai:models:{$provider}");
        if ($cached !== null && count($cached) > 0) {
            return $cached;
        }

        $result = $this->fetch($provider, $apiKey);

        if (count($result) > 0) {
            Cache::put("ai:models:{$provider}", $result, now()->addHours(6));
        }

        return $result;
    }

    /** @return list<string> */
    private function fetch(string $provider, ?string $apiKey): array
    {
        return match ($provider) {
            'anthropic' => $this->fetchAnthropic($apiKey),
            'gemini' => $this->fetchGemini($apiKey),
            'ollama' => $this->fetchOllama(),
            default => $this->fetchOpenAiCompatible($provider, $apiKey),
        };
    }

    /** @return list<string> */
    private function fetchAnthropic(?string $apiKey): array
    {
        $url = rtrim((string) config('prism.providers.anthropic.url'), '/');
        $version = (string) config('prism.providers.anthropic.version', '2023-06-01');

        $response = Http::timeout(10)
            ->withHeaders([
                'x-api-key' => (string) $apiKey,
                'anthropic-version' => $version,
            ])
            ->get("{$url}/models");

        if (! $response->successful()) {
            throw new ModelCatalogException(
                "Could not list models (HTTP {$response->status()}). Check the API key."
            );
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function fetchGemini(?string $apiKey): array
    {
        $url = rtrim((string) config('prism.providers.gemini.url'), '/');

        $response = Http::timeout(10)->get($url, ['key' => $apiKey]);

        if (! $response->successful()) {
            throw new ModelCatalogException(
                "Could not list models (HTTP {$response->status()}). Check the API key."
            );
        }

        return collect($response->json('models', []))
            ->filter(fn (array $m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->pluck('name')
            ->map(fn (string $name) => str_replace('models/', '', $name))
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function fetchOllama(): array
    {
        $url = rtrim((string) config('prism.providers.ollama.url'), '/');

        $response = Http::timeout(10)->get("{$url}/api/tags");

        if (! $response->successful()) {
            throw new ModelCatalogException(
                "Could not list models (HTTP {$response->status()}). Check Ollama is running."
            );
        }

        return collect($response->json('models', []))
            ->pluck('name')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function fetchOpenAiCompatible(string $provider, ?string $apiKey): array
    {
        $url = rtrim((string) config("prism.providers.{$provider}.url"), '/');

        $response = Http::timeout(10)
            ->withToken((string) $apiKey)
            ->get("{$url}/models");

        if (! $response->successful()) {
            throw new ModelCatalogException(
                "Could not list models (HTTP {$response->status()}). Check the API key."
            );
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }
}
