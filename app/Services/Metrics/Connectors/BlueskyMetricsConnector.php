<?php

declare(strict_types=1);

namespace App\Services\Metrics\Connectors;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Contracts\MetricsConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

class BlueskyMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    private const string APPVIEW = 'https://public.api.bsky.app';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $uris = $target->remote_ids ?? array_filter([$target->remote_id]);

        if ($uris === []) {
            return PostMetricsResult::failed('Target has no remote ids.');
        }

        $query = implode('&', array_map(
            fn (string $uri): string => 'uris='.rawurlencode($uri),
            array_slice($uris, 0, 25),
        ));

        try {
            $response = $this->http->acceptJson()->get(self::APPVIEW.'/xrpc/app.bsky.feed.getPosts?'.$query);
        } catch (ConnectionException $e) {
            return PostMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed()) {
            return $response->status() === 429
                ? PostMetricsResult::rateLimited($this->excerpt($response))
                : PostMetricsResult::failed($this->excerpt($response));
        }

        /** @var list<array<string, mixed>> $posts */
        $posts = $response->json('posts', []);

        $likes = 0;
        $reposts = 0;
        $replies = 0;

        foreach ($posts as $post) {
            $likes += (int) ($post['likeCount'] ?? 0);
            $reposts += (int) ($post['repostCount'] ?? 0) + (int) ($post['quoteCount'] ?? 0);
            $replies += (int) ($post['replyCount'] ?? 0);
        }

        $comments = max(0, $replies - max(0, count($posts) - 1));

        return PostMetricsResult::ok($likes, $comments, $reposts, raw: ['posts' => $posts]);
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->http->acceptJson()->get(self::APPVIEW.'/xrpc/app.bsky.actor.getProfile', [
                'actor' => $account->remote_account_id,
            ]);
        } catch (ConnectionException $e) {
            return AccountMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_ACCOUNT, $account, $response);

        if ($response->failed()) {
            return AccountMetricsResult::failed($this->excerpt($response));
        }

        return AccountMetricsResult::ok(
            followers: (int) $response->json('followersCount', 0),
            following: (int) $response->json('followsCount', 0),
            postsCount: (int) $response->json('postsCount', 0),
            raw: ['profile' => $response->json()],
        );
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('message') ?? $response->json('error') ?? mb_substr($response->body(), 0, 200));
    }
}
