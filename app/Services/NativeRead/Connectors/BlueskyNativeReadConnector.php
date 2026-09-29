<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

class BlueskyNativeReadConnector implements NativeReadConnector
{
    private const string APPVIEW = 'https://public.api.bsky.app';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
                ->get(self::APPVIEW.'/xrpc/app.bsky.feed.getAuthorFeed', [
                    'actor' => (string) $account->remote_account_id,
                    'limit' => 50,
                    'filter' => 'posts_no_replies',
                ]);
        } catch (ConnectionException $e) {
            return RecentPostsResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $response->status() === 429
                ? RecentPostsResult::rateLimited($response->body())
                : RecentPostsResult::failed($response->body());
        }

        $posts = [];
        $newest = null;
        foreach ((array) $response->json('feed', []) as $item) {
            $isRepost = isset($item['reason']);
            $record = $item['post']['record'] ?? [];
            $isReply = isset($record['reply']);
            $uri = (string) ($item['post']['uri'] ?? '');
            $createdAt = Carbon::parse((string) ($record['createdAt'] ?? 'now'))->toImmutable();

            if ($uri === '' || $isRepost || $isReply || $createdAt < $cursor->watermark) {
                continue;
            }

            $newest ??= $uri;
            $posts[] = new NativePost(
                remoteId: $uri,
                text: (string) ($record['text'] ?? ''),
                createdAt: $createdAt,
                media: $this->media($item['post']['embed'] ?? null),
                isReply: false,
                isRepost: false,
            );
        }

        return RecentPostsResult::ok($posts, $newest);
    }

    /**
     * @return list<NativeMedia>
     */
    private function media(mixed $embed): array
    {
        if (! is_array($embed)) {
            return [];
        }

        $out = [];
        foreach (($embed['images'] ?? []) as $image) {
            $url = (string) ($image['fullsize'] ?? $image['thumb'] ?? '');
            if ($url !== '') {
                $out[] = new NativeMedia($url, 'image');
            }
        }

        return $out;
    }
}
