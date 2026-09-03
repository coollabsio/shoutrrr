<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

class XNativeReadConnector implements NativeReadConnector
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/users/'.$account->remote_account_id.'/tweets', [
                    'exclude' => 'replies,retweets',
                    'max_results' => 100,
                    'start_time' => $cursor->watermark->toIso8601ZuluString(),
                    'tweet.fields' => 'created_at',
                ]);
        } catch (ConnectionException $e) {
            return RecentPostsResult::failed($e->getMessage());
        }

        /** @var list<array<string, mixed>> $tweets */
        $tweets = $response->successful() ? (array) $response->json('data', []) : [];

        $this->meterRead(
            UsageCategory::ExternalApi,
            UsageOperation::X_READ,
            $account,
            $response,
            array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $tweets),
        );

        if ($response->failed()) {
            return $response->status() === 429
                ? RecentPostsResult::rateLimited($response->body())
                : RecentPostsResult::failed($response->body());
        }

        $posts = [];
        $newest = null;
        foreach ($tweets as $tweet) {
            $id = (string) ($tweet['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $newest ??= $id;
            $posts[] = new NativePost(
                remoteId: $id,
                text: (string) ($tweet['text'] ?? ''),
                createdAt: Carbon::parse((string) ($tweet['created_at'] ?? 'now'))->toImmutable(),
                media: [], // X media requires expansions; v1 mirrors text (media is reference-less)
                isReply: false,
                isRepost: false,
            );
        }

        return RecentPostsResult::ok($posts, $newest);
    }
}
