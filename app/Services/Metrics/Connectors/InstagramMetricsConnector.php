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

class InstagramMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    public function __construct(private readonly HttpFactory $http) {}

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', config('services.facebook.graph_version'));
    }

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $mediaId = $target->remote_id;

        if ($mediaId === null) {
            return PostMetricsResult::failed('Target has no remote id.');
        }

        $token = (string) ($credentials['access_token'] ?? '');

        try {
            $response = $this->http
                ->acceptJson()
                ->get($this->baseUrl().'/'.$mediaId.'/insights', [
                    'metric' => 'likes,comments,saved,shares,reach,views',
                    'access_token' => $token,
                ]);
        } catch (ConnectionException $e) {
            return PostMetricsResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

        if ($response->failed()) {
            if ($response->status() === 429) {
                return PostMetricsResult::rateLimited($this->excerpt($response));
            }

            // Non-fatal: some metrics (e.g. impressions on young media) can 400.
            // Mirror FacebookMetricsConnector's non-fatal insights handling by
            // returning ok with whatever we could map (defaulting to zero/null).
            return PostMetricsResult::ok(0, 0, 0);
        }

        $metrics = [];

        foreach ((array) $response->json('data', []) as $entry) {
            $name = $entry['name'] ?? null;
            $value = $entry['values'][0]['value'] ?? null;

            if ($name !== null) {
                $metrics[$name] = $value;
            }
        }

        $likes = (int) ($metrics['likes'] ?? 0);
        $comments = (int) ($metrics['comments'] ?? 0);
        $reposts = (int) ($metrics['shares'] ?? 0);

        $impressions = $metrics['views'] ?? $metrics['reach'] ?? null;
        $impressions = is_numeric($impressions) ? (int) $impressions : null;

        return PostMetricsResult::ok($likes, $comments, $reposts, $impressions, $response->json());
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->get($this->baseUrl().'/'.$account->remote_account_id, [
                    'fields' => 'followers_count,media_count',
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

        $followers = (int) $response->json('followers_count', 0);
        $postsCount = (int) $response->json('media_count', 0);

        return AccountMetricsResult::ok($followers, postsCount: $postsCount, raw: $response->json());
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
