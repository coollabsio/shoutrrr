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

class FacebookMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    public function __construct(private readonly HttpFactory $http) {}

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', config('services.facebook.graph_version'));
    }

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $postId = $target->remote_id;

        if ($postId === null) {
            return PostMetricsResult::failed('Target has no remote id.');
        }

        $token = (string) ($credentials['access_token'] ?? '');

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get($this->baseUrl().'/'.$postId, [
                    'fields' => 'likes.summary(true),comments.summary(true),shares',
                    'access_token' => $token,
                ]);
        } catch (ConnectionException $e) {
            return PostMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed()) {
            return match (true) {
                $response->status() === 429 => PostMetricsResult::rateLimited($this->excerpt($response)),
                default => PostMetricsResult::failed($this->excerpt($response)),
            };
        }

        $likes = (int) $response->json('likes.summary.total_count', 0);
        $comments = (int) $response->json('comments.summary.total_count', 0);
        $shares = (int) $response->json('shares.count', 0);

        $impressions = $this->fetchImpressions($account, $postId, $token);

        return PostMetricsResult::ok($likes, $comments, $shares, $impressions, $response->json());
    }

    private function fetchImpressions(ConnectedAccount $account, string $postId, string $token): ?int
    {
        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get($this->baseUrl().'/'.$postId.'/insights', [
                    'metric' => 'post_impressions',
                    'access_token' => $token,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed()) {
            return null;
        }

        $value = $response->json('data.0.values.0.value');

        return is_numeric($value) ? (int) $value : null;
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get($this->baseUrl().'/'.$account->remote_account_id, [
                    'fields' => 'followers_count,fan_count',
                    'access_token' => (string) ($credentials['access_token'] ?? ''),
                ]);
        } catch (ConnectionException $e) {
            return AccountMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_ACCOUNT, $account, $response);

        if ($response->failed()) {
            return match (true) {
                $response->status() === 429 => AccountMetricsResult::rateLimited($this->excerpt($response)),
                default => AccountMetricsResult::failed($this->excerpt($response)),
            };
        }

        $followers = $response->json('followers_count') ?? $response->json('fan_count', 0);

        return AccountMetricsResult::ok((int) $followers, raw: $response->json());
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
