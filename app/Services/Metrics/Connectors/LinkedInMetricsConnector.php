<?php

declare(strict_types=1);

namespace App\Services\Metrics\Connectors;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Contracts\MetricsConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Real organic analytics for LinkedIn Pages (Organizations): per-post share
 * statistics and Page follower counts via the Community Management API
 * (`rw_organization_admin`). Personal member accounts have no such API, so they
 * stay `unsupported` (terminal) exactly as before.
 */
class LinkedInMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    private const string SHARE_STATS_URL = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics';

    private const string NETWORK_SIZE_URL = 'https://api.linkedin.com/rest/networkSizes';

    private const string PERSON_REASON = 'LinkedIn personal-account analytics are not available via the API.';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        if (! $account->isLinkedInOrganization()) {
            return PostMetricsResult::unsupported(self::PERSON_REASON);
        }

        $shareUrn = $target->remote_ids[0] ?? $target->remote_id;

        if ($shareUrn === null) {
            return PostMetricsResult::failed('Target has no remote id.');
        }

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->get(self::SHARE_STATS_URL, [
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => $account->linkedInAuthorUrn(),
                    'shares' => 'List('.$shareUrn.')',
                ]);
        } catch (ConnectionException $e) {
            return PostMetricsResult::failed($e->getMessage());
        }

        $this->meterRead(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response, [(string) $shareUrn]);

        if ($response->failed()) {
            return $this->mapPostFailure($response);
        }

        /** @var list<array<string, mixed>> $elements */
        $elements = $response->json('elements', []);
        // Shares with no actions/impressions are omitted → treat as all-zero.
        $stats = (array) ($elements[0]['totalShareStatistics'] ?? []);

        return PostMetricsResult::ok(
            likes: (int) ($stats['likeCount'] ?? 0),
            comments: (int) ($stats['commentCount'] ?? 0),
            reposts: (int) ($stats['shareCount'] ?? 0),
            impressions: (int) ($stats['impressionCount'] ?? 0),
            raw: ['elements' => $elements],
        );
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        if (! $account->isLinkedInOrganization()) {
            return AccountMetricsResult::unsupported(self::PERSON_REASON);
        }

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->get(self::NETWORK_SIZE_URL.'/'.rawurlencode($account->linkedInAuthorUrn()), [
                    'edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER',
                ]);
        } catch (ConnectionException $e) {
            return AccountMetricsResult::failed($e->getMessage());
        }

        $this->meterRead(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_ACCOUNT, $account, $response, [(string) $account->remote_account_id]);

        if ($response->failed()) {
            return match (true) {
                $response->status() === 403 => AccountMetricsResult::unsupported($this->excerpt($response)),
                $response->status() === 429 => AccountMetricsResult::rateLimited($this->excerpt($response)),
                default => AccountMetricsResult::failed($this->excerpt($response)),
            };
        }

        return AccountMetricsResult::ok(
            followers: (int) $response->json('firstDegreeSize', 0),
            raw: ['networkSize' => $response->json()],
        );
    }

    private function mapPostFailure(Response $response): PostMetricsResult
    {
        return match (true) {
            $response->status() === 403 => PostMetricsResult::unsupported($this->excerpt($response)),
            $response->status() === 429 => PostMetricsResult::rateLimited($this->excerpt($response)),
            default => PostMetricsResult::failed($this->excerpt($response)),
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'LinkedIn-Version' => (string) config('services.linkedin-openid.api_version', LinkedInConnector::DEFAULT_VERSION),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('message') ?? mb_substr($response->body(), 0, 200));
    }
}
