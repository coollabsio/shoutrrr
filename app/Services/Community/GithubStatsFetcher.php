<?php

declare(strict_types=1);

namespace App\Services\Community;

use Illuminate\Support\Facades\Http;
use Throwable;

class GithubStatsFetcher
{
    /**
     * @return array{stars: ?int, latest_stable: ?string, latest_overall: ?string}
     */
    public function fetch(): array
    {
        $repo = (string) config('instance.community.repo');
        $releases = $this->releases($repo);

        return [
            'stars' => $this->stars($repo),
            'latest_stable' => $releases['stable'],
            'latest_overall' => $releases['overall'],
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

    /**
     * Newest non-draft release tag among stable-only and among all channels.
     *
     * @return array{stable: ?string, overall: ?string}
     */
    private function releases(string $repo): array
    {
        try {
            // Newest 100 releases by creation date; ample for any realistic release cadence.
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 100]);

            if (! $response->successful()) {
                return ['stable' => null, 'overall' => null];
            }

            $releases = $response->json();

            if (! is_array($releases)) {
                return ['stable' => null, 'overall' => null];
            }

            $all = [];
            $stable = [];

            foreach ($releases as $release) {
                if (! is_array($release) || ($release['draft'] ?? false) === true) {
                    continue;
                }

                $tag = $release['tag_name'] ?? null;

                if (! is_string($tag) || $tag === '') {
                    continue;
                }

                $all[] = $tag;

                if (($release['prerelease'] ?? false) !== true) {
                    $stable[] = $tag;
                }
            }

            return [
                'stable' => $this->maxTag($stable),
                'overall' => $this->maxTag($all),
            ];
        } catch (Throwable) {
            return ['stable' => null, 'overall' => null];
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function maxTag(array $tags): ?string
    {
        $max = null;

        foreach ($tags as $tag) {
            if ($max === null || version_compare(ltrim($tag, 'v'), ltrim($max, 'v'), '>')) {
                $max = $tag;
            }
        }

        return $max;
    }
}
