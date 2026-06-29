<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
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

        return $this->parseDataIds($response);
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

        $models = $response->json('models');
        $names = [];

        if (is_array($models)) {
            foreach ($models as $model) {
                if (! is_array($model)) {
                    continue;
                }

                $methods = $model['supportedGenerationMethods'] ?? [];
                if (! is_array($methods) || ! in_array('generateContent', $methods, true)) {
                    continue;
                }

                $name = $model['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $names[] = str_replace('models/', '', $name);
                }
            }
        }

        return $this->normalize($names);
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

        $models = $response->json('models');
        $names = [];

        if (is_array($models)) {
            foreach ($models as $model) {
                if (is_array($model) && isset($model['name']) && is_string($model['name']) && $model['name'] !== '') {
                    $names[] = $model['name'];
                }
            }
        }

        return $this->normalize($names);
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

        return $this->parseDataIds($response);
    }

    /**
     * Extract model ids from an OpenAI-style `{ data: [ { id }, ... ] }` body.
     *
     * @return list<string>
     */
    private function parseDataIds(Response $response): array
    {
        $data = $response->json('data');
        $ids = [];

        if (is_array($data)) {
            foreach ($data as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id']) && $item['id'] !== '') {
                    $ids[] = $item['id'];
                }
            }
        }

        return $this->normalize($ids);
    }

    /**
     * Sort, de-duplicate, and re-key into a clean list.
     *
     * @param  list<string>  $models
     * @return list<string>
     */
    private function normalize(array $models): array
    {
        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }
}
