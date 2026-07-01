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

class XMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $ids = $target->remote_ids ?? array_filter([$target->remote_id]);

        if ($ids === []) {
            return PostMetricsResult::failed('Target has no remote ids.');
        }

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/tweets', [
                    'ids' => implode(',', array_slice($ids, 0, 100)),
                    'tweet.fields' => 'public_metrics',
                ]);
        } catch (ConnectionException $e) {
            return PostMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed()) {
            return match (true) {
                $response->status() === 403 => PostMetricsResult::unsupported($this->excerpt($response)),
                $response->status() === 429 => PostMetricsResult::rateLimited($this->excerpt($response)),
                default => PostMetricsResult::failed($this->excerpt($response)),
            };
        }

        /** @var list<array<string, mixed>> $tweets */
        $tweets = $response->json('data', []);

        $likes = 0;
        $reposts = 0;
        $replies = 0;
        $impressions = 0;

        foreach ($tweets as $tweet) {
            $m = (array) ($tweet['public_metrics'] ?? []);
            $likes += (int) ($m['like_count'] ?? 0);
            $reposts += (int) ($m['retweet_count'] ?? 0) + (int) ($m['quote_count'] ?? 0);
            $replies += (int) ($m['reply_count'] ?? 0);
            $impressions += (int) ($m['impression_count'] ?? 0);
        }

        $comments = max(0, $replies - max(0, count($tweets) - 1));

        return PostMetricsResult::ok($likes, $comments, $reposts, $impressions, ['tweets' => $tweets]);
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/users/'.$account->remote_account_id, ['user.fields' => 'public_metrics']);
        } catch (ConnectionException $e) {
            return AccountMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_ACCOUNT, $account, $response);

        if ($response->failed()) {
            return match (true) {
                $response->status() === 403 => AccountMetricsResult::unsupported($this->excerpt($response)),
                $response->status() === 429 => AccountMetricsResult::rateLimited($this->excerpt($response)),
                default => AccountMetricsResult::failed($this->excerpt($response)),
            };
        }

        $m = (array) $response->json('data.public_metrics', []);

        return AccountMetricsResult::ok(
            followers: (int) ($m['followers_count'] ?? 0),
            following: (int) ($m['following_count'] ?? 0),
            postsCount: (int) ($m['tweet_count'] ?? 0),
            raw: ['user' => $response->json('data')],
        );
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('title') ?? $response->json('detail') ?? mb_substr($response->body(), 0, 200));
    }
}
