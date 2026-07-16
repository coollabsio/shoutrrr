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

class ThreadsMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    private const string BASE_URL = 'https://graph.threads.net/v1.0';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $mediaId = $target->remote_id;

        if ($mediaId === null) {
            return PostMetricsResult::failed('Target has no remote id.');
        }

        $token = (string) ($credentials['access_token'] ?? '');

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get(self::BASE_URL.'/'.$mediaId.'/insights', [
                    'metric' => 'views,likes,replies,reposts,quotes,shares',
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

            return PostMetricsResult::failed($this->excerpt($response));
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
        $comments = (int) ($metrics['replies'] ?? 0);
        $reposts = (int) ($metrics['reposts'] ?? 0);

        $impressions = $metrics['views'] ?? null;
        $impressions = is_numeric($impressions) ? (int) $impressions : null;

        return PostMetricsResult::ok($likes, $comments, $reposts, $impressions, $response->json());
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get(self::BASE_URL.'/'.$account->remote_account_id.'/threads_insights', [
                    'metric' => 'followers_count',
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

        $followers = 0;

        foreach ((array) $response->json('data', []) as $entry) {
            if (($entry['name'] ?? null) !== 'followers_count') {
                continue;
            }

            $value = $entry['total_value']['value'] ?? $entry['values'][0]['value'] ?? 0;
            $followers = is_numeric($value) ? (int) $value : 0;
        }

        return AccountMetricsResult::ok($followers, raw: $response->json());
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
