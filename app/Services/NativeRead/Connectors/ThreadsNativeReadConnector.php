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

class ThreadsNativeReadConnector implements NativeReadConnector
{
    private const string BASE = 'https://graph.threads.net/v1.0';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
                ->get(self::BASE.'/me/threads', [
                    'fields' => 'id,text,media_type,media_url,timestamp,children{media_type,media_url}',
                    'since' => $cursor->watermark->timestamp,
                    'limit' => 50,
                    'access_token' => (string) ($credentials['access_token'] ?? ''),
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
        foreach ((array) $response->json('data', []) as $row) {
            $id = (string) ($row['id'] ?? '');
            $createdAt = Carbon::parse((string) ($row['timestamp'] ?? 'now'))->toImmutable();
            if ($id === '' || $createdAt < $cursor->watermark) {
                continue;
            }

            $newest ??= $id;
            $posts[] = new NativePost($id, (string) ($row['text'] ?? ''), $createdAt, $this->media($row), false, false);
        }

        return RecentPostsResult::ok($posts, $newest);
    }

    /**
     * A carousel carries its items in `children`; single posts carry their own media_url.
     *
     * @param  array<string, mixed>  $row
     * @return list<NativeMedia>
     */
    private function media(array $row): array
    {
        $items = ($row['media_type'] ?? null) === 'CAROUSEL_ALBUM' ? (array) ($row['children']['data'] ?? []) : [$row];

        $media = [];
        foreach ($items as $item) {
            $url = (string) ($item['media_url'] ?? '');
            $type = (string) ($item['media_type'] ?? '');
            if ($url !== '' && in_array($type, ['IMAGE', 'VIDEO'], true)) {
                $media[] = new NativeMedia($url, $type === 'VIDEO' ? 'video' : 'image');
            }
        }

        return $media;
    }
}
