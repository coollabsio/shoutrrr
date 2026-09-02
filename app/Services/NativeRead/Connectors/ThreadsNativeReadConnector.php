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
                    'fields' => 'id,text,media_type,media_url,timestamp',
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

            $media = [];
            $type = (string) ($row['media_type'] ?? 'TEXT');
            $url = (string) ($row['media_url'] ?? '');
            if ($url !== '' && $type === 'IMAGE') {
                $media[] = new NativeMedia($url, 'image');
            } elseif ($url !== '' && $type === 'VIDEO') {
                $media[] = new NativeMedia($url, 'video');
            }

            $newest ??= $id;
            $posts[] = new NativePost($id, (string) ($row['text'] ?? ''), $createdAt, $media, false, false);
        }

        return RecentPostsResult::ok($posts, $newest);
    }
}
