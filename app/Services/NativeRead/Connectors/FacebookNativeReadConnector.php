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

class FacebookNativeReadConnector implements NativeReadConnector
{
    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)->acceptJson()
                ->get($this->baseUrl().'/'.$account->remote_account_id.'/feed', [
                    'fields' => 'id,message,created_time,full_picture',
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
            $createdAt = Carbon::parse((string) ($row['created_time'] ?? 'now'))->toImmutable();
            if ($id === '' || $createdAt < $cursor->watermark) {
                continue;
            }
            $media = [];
            $picture = (string) ($row['full_picture'] ?? '');
            if ($picture !== '') {
                $media[] = new NativeMedia($picture, 'image');
            }
            $newest ??= $id;
            $posts[] = new NativePost($id, (string) ($row['message'] ?? ''), $createdAt, $media, false, false);
        }

        return RecentPostsResult::ok($posts, $newest);
    }

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', (string) config('services.facebook.graph_version'));
    }
}
