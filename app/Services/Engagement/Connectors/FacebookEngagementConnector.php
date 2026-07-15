<?php

declare(strict_types=1);

namespace App\Services\Engagement\Connectors;

use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Engagement\RetryAfter;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Reads and writes comments on a Facebook Page's posts via the Graph API,
 * using the Page access token stored on the connected account. Reading
 * requires `pages_read_user_content`/`pages_read_engagement` and writing
 * requires `pages_manage_engagement`; without them Facebook returns 403,
 * which we map to `Unsupported` so the inbox degrades cleanly rather than
 * erroring.
 *
 * The Graph API rejects media on comments, so reply media is declined with a
 * clear message.
 */
class FacebookEngagementConnector implements EngagementConnector
{
    use TracksUsage;

    public function __construct(private readonly HttpFactory $http) {}

    private function apiVersion(): string
    {
        return (string) config('services.facebook.graph_version');
    }

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', $this->apiVersion());
    }

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        $postId = $target->remote_id;

        if ($postId === null) {
            return ReplyFetchResult::failed('Target has no remote id.');
        }

        $query = [
            'fields' => 'id,message,from,created_time,like_count',
            'filter' => 'toplevel',
            'order' => 'chronological',
            'access_token' => (string) ($credentials['access_token'] ?? ''),
        ];

        if ($since !== null) {
            $query['since'] = $since->getTimestamp();
        }

        try {
            $response = $this->http->get($this->baseUrl().'/'.$postId.'/comments', $query);
        } catch (ConnectionException $e) {
            return ReplyFetchResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, $response);

        if ($response->failed()) {
            return $this->mapFetchFailure($response);
        }

        $replies = [];
        foreach ((array) $response->json('data', []) as $comment) {
            $id = (string) ($comment['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $createdAtRaw = (string) ($comment['created_time'] ?? '');
            $createdAt = $createdAtRaw !== ''
                ? CarbonImmutable::parse($createdAtRaw)
                : CarbonImmutable::now();

            $authorName = $comment['from']['name'] ?? null;

            $replies[] = new FetchedReply(
                remoteReplyId: $id,
                remoteCid: null,
                parentRemoteId: $postId,
                authorHandle: (string) ($authorName ?? ''),
                authorName: $authorName !== null ? (string) $authorName : null,
                authorAvatarUrl: null,
                text: (string) ($comment['message'] ?? ''),
                remoteCreatedAt: $createdAt,
            );
        }

        return ReplyFetchResult::ok($replies);
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials, array $media = []): ReplyPostResult
    {
        if ($media !== []) {
            return ReplyPostResult::unsupported('Facebook does not support media on comments');
        }

        try {
            $response = $this->http
                ->asForm()
                ->post($this->baseUrl().'/'.$parent->remote_reply_id.'/comments', [
                    'message' => $text,
                    'access_token' => (string) ($credentials['access_token'] ?? ''),
                ]);
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_SEND, $account, $response);

        if ($response->failed()) {
            return match (true) {
                $response->status() === 401 => ReplyPostResult::authExpired($this->excerpt($response)),
                $response->status() === 403 => ReplyPostResult::unsupported($this->excerpt($response)),
                $response->status() === 429 => ReplyPostResult::rateLimited($this->excerpt($response)),
                default => ReplyPostResult::failed($this->excerpt($response)),
            };
        }

        return ReplyPostResult::ok((string) $response->json('id'));
    }

    public function likeReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        try {
            $response = $this->http
                ->asForm()
                ->post($this->baseUrl().'/'.$reply->remote_reply_id.'/likes', [
                    'access_token' => (string) ($credentials['access_token'] ?? ''),
                ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_LIKE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    public function unlikeReply(ConnectedAccount $account, PostTargetReply $reply, ?string $likeRemoteId, array $credentials): ReplyActionResult
    {
        try {
            $response = $this->http->delete($this->baseUrl().'/'.$reply->remote_reply_id.'/likes', [
                'access_token' => (string) ($credentials['access_token'] ?? ''),
            ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_UNLIKE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    public function deleteReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        try {
            $response = $this->http->delete($this->baseUrl().'/'.$reply->remote_reply_id, [
                'access_token' => (string) ($credentials['access_token'] ?? ''),
            ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_DELETE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    private function mapActionFailure(Response $response): ReplyActionResult
    {
        return match (true) {
            $response->status() === 401 => ReplyActionResult::authExpired($this->excerpt($response)),
            $response->status() === 403 => ReplyActionResult::unsupported($this->excerpt($response)),
            $response->status() === 429 => ReplyActionResult::rateLimited($this->excerpt($response)),
            default => ReplyActionResult::failed($this->excerpt($response)),
        };
    }

    private function mapFetchFailure(Response $response): ReplyFetchResult
    {
        return match (true) {
            $response->status() === 401 => ReplyFetchResult::authExpired($this->excerpt($response)),
            $response->status() === 403 => ReplyFetchResult::unsupported($this->excerpt($response)),
            $response->status() === 429 => ReplyFetchResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
            default => ReplyFetchResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));
    }
}
