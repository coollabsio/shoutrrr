<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\BlueskyConnector;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Throwable;

class BlueskyNativeReadConnector implements NativeReadConnector
{
    private const string APPVIEW = 'https://public.api.bsky.app';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly BlueskyConnector $bluesky,
    ) {}

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

        $feed = (array) $response->json('feed', []);
        $hasVideo = array_any($feed, fn (mixed $item): bool => $this->videoCid($item['post']['embed'] ?? null) !== null);
        $blobBase = $hasVideo ? $this->blobBase($account, $credentials) : null;

        $posts = [];
        $newest = null;
        foreach ($feed as $item) {
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
                media: $this->media($item['post']['embed'] ?? null, $blobBase),
                isReply: false,
                isRepost: false,
            );
        }

        return RecentPostsResult::ok($posts, $newest);
    }

    /**
     * getBlob URL prefix on the author's PDS: the original video blob lives there,
     * the AppView only serves HLS. The bsky.social entryway answers getBlob with a
     * redirect (which the SSRF-safe fetcher refuses), so resolve the real PDS.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function blobBase(ConnectedAccount $account, array $credentials): ?string
    {
        $did = (string) $account->remote_account_id;
        $pds = $credentials['session']['pds'] ?? null;
        if (! is_string($pds) || $pds === '' || parse_url($pds, PHP_URL_HOST) === 'bsky.social') {
            try {
                $pds = $this->bluesky->resolveDidToPds($did);
            } catch (Throwable) {
                $pds = null;
            }
        }

        return is_string($pds) && $pds !== ''
            ? rtrim($pds, '/').'/xrpc/com.atproto.sync.getBlob?did='.rawurlencode($did).'&cid='
            : null;
    }

    /**
     * @return list<NativeMedia>
     */
    private function media(mixed $embed, ?string $blobBase): array
    {
        if (! is_array($embed)) {
            return [];
        }

        $cid = $this->videoCid($embed);
        if ($cid !== null) {
            return $blobBase === null ? [] : [new NativeMedia($blobBase.rawurlencode($cid), 'video', 'video/mp4')];
        }

        // A quote post (recordWithMedia) nests its own images/gallery under `media`.
        $embed = is_array($embed['media'] ?? null) ? $embed['media'] : $embed;

        $out = [];
        foreach (($embed['images'] ?? $embed['items'] ?? []) as $image) {
            $url = (string) ($image['fullsize'] ?? $image['thumb'] ?? '');
            if ($url !== '') {
                $out[] = new NativeMedia($url, 'image');
            }
        }

        return $out;
    }

    /**
     * The blob CID of a video embed, also when nested in a quote post's `media`.
     */
    private function videoCid(mixed $embed): ?string
    {
        if (! is_array($embed)) {
            return null;
        }
        $embed = is_array($embed['media'] ?? null) ? $embed['media'] : $embed;

        return isset($embed['cid'], $embed['playlist']) ? (string) $embed['cid'] : null;
    }
}
