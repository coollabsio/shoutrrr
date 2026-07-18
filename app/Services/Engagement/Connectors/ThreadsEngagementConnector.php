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
 * Reads and writes replies on a Threads post via the Threads Graph API, using
 * the account's Threads long-lived token. Requires `threads_read_replies` /
 * `threads_manage_replies`; without them Threads returns 403, which we map to
 * `Unsupported` so the inbox degrades cleanly rather than erroring.
 *
 * Threads has no dedicated reply endpoint: a reply is a normal two-step
 * container publish (create → poll → publish) with `reply_to_id` set on the
 * container-creation call, mirroring `ThreadsConnector::publish()`. Media on
 * replies is declined in this first cut. Threads has no documented
 * reply-like API, so like/unlike always return `Unsupported` without making
 * a request.
 */
class ThreadsEngagementConnector implements EngagementConnector
{
    use TracksUsage;

    private const string BASE_URL = 'https://graph.threads.net/v1.0';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        $mediaId = $target->remote_id;

        if ($mediaId === null) {
            return ReplyFetchResult::failed('Target has no remote id.');
        }

        $query = [
            'fields' => 'id,text,username,timestamp,hide_status',
            'access_token' => (string) ($credentials['access_token'] ?? ''),
        ];

        try {
            $response = $this->http->get(self::BASE_URL.'/'.$mediaId.'/replies', $query);
        } catch (ConnectionException $e) {
            return ReplyFetchResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, $response);

        if ($response->failed()) {
            return $this->mapFetchFailure($response);
        }

        $replies = [];
        foreach ((array) $response->json('data', []) as $reply) {
            $id = (string) ($reply['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $createdAtRaw = (string) ($reply['timestamp'] ?? '');
            $createdAt = $createdAtRaw !== ''
                ? CarbonImmutable::parse($createdAtRaw)
                : CarbonImmutable::now();

            $username = $reply['username'] ?? null;

            $replies[] = new FetchedReply(
                remoteReplyId: $id,
                remoteCid: null,
                parentRemoteId: $mediaId,
                authorHandle: (string) ($username ?? ''),
                authorName: $username !== null ? (string) $username : null,
                authorAvatarUrl: null,
                text: (string) ($reply['text'] ?? ''),
                remoteCreatedAt: $createdAt,
            );
        }

        return ReplyFetchResult::ok($replies);
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials, array $media = []): ReplyPostResult
    {
        if ($media !== []) {
            return ReplyPostResult::unsupported('Threads does not support media on replies');
        }

        $token = (string) ($credentials['access_token'] ?? '');
        $threadsUserId = (string) $account->remote_account_id;

        try {
            $container = $this->http->asForm()->post(self::BASE_URL.'/'.$threadsUserId.'/threads', [
                'media_type' => 'TEXT',
                'text' => $text,
                'reply_to_id' => $parent->remote_reply_id,
                'access_token' => $token,
            ]);
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_SEND, $account, $container);

        if ($container->failed()) {
            return $this->mapPostFailure($container);
        }

        $containerId = (string) $container->json('id');

        if ($containerId === '') {
            return ReplyPostResult::failed('Threads did not return a container id');
        }

        try {
            $status = $this->http->get(self::BASE_URL.'/'.$containerId, [
                'fields' => 'status',
                'access_token' => $token,
            ]);
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_STATUS_POLL, $account, $status);

        if ($status->failed()) {
            return $this->mapPostFailure($status);
        }

        if ((string) $status->json('status') !== 'FINISHED') {
            return ReplyPostResult::failed('Threads reply is still processing');
        }

        try {
            $publish = $this->http->asForm()->post(self::BASE_URL.'/'.$threadsUserId.'/threads_publish', [
                'creation_id' => $containerId,
                'access_token' => $token,
            ]);
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_SEND, $account, $publish);

        if ($publish->failed()) {
            return $this->mapPostFailure($publish);
        }

        return ReplyPostResult::ok((string) $publish->json('id'));
    }

    public function likeReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        return ReplyActionResult::unsupported('Threads does not support liking replies via API');
    }

    public function unlikeReply(ConnectedAccount $account, PostTargetReply $reply, ?string $likeRemoteId, array $credentials): ReplyActionResult
    {
        return ReplyActionResult::unsupported('Threads does not support liking replies via API');
    }

    public function deleteReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        try {
            $response = $this->http->delete(self::BASE_URL.'/'.$reply->remote_reply_id, [
                'access_token' => (string) ($credentials['access_token'] ?? ''),
            ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_DELETE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    private function mapPostFailure(Response $response): ReplyPostResult
    {
        return match (true) {
            $response->status() === 401 => ReplyPostResult::authExpired($this->excerpt($response)),
            $response->status() === 403 => ReplyPostResult::unsupported($this->excerpt($response)),
            $response->status() === 429 => ReplyPostResult::rateLimited($this->excerpt($response)),
            default => ReplyPostResult::failed($this->excerpt($response)),
        };
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
