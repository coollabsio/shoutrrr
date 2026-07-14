<?php

declare(strict_types=1);

namespace App\Services\Community;

use Illuminate\Support\Facades\Http;
use Throwable;

class GithubStatsFetcher
{
    /**
     * @return array{stars: ?int, latest_version: ?string}
     */
    public function fetch(): array
    {
        $repo = (string) config('instance.community.repo');

        return [
            'stars' => $this->stars($repo),
            'latest_version' => $this->latestVersion($repo),
        ];
    }

    private function stars(string $repo): ?int
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}");

            if (! $response->successful()) {
                return null;
            }

            $count = $response->json('stargazers_count');

            return is_numeric($count) ? (int) $count : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function latestVersion(string $repo): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if (! $response->successful()) {
                return null;
            }

            $tag = $response->json('tag_name');

            return is_string($tag) && $tag !== '' ? $tag : null;
        } catch (Throwable) {
            return null;
        }
    }
}
