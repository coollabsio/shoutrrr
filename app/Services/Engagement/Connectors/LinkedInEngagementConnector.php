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
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Reads and writes comments on a member's LinkedIn posts via the Community
 * Management `socialActions` API (the same versioned REST surface the publish
 * connector uses). Reading requires the `r_member_social_feed` permission and
 * writing `w_member_social_feed`; without them LinkedIn returns 403, which we
 * map to `Unsupported` so the inbox degrades cleanly rather than erroring.
 *
 * LinkedIn's API rejects media on app-created comments, so reply media is
 * declined with a clear message.
 */
class LinkedInEngagementConnector implements EngagementConnector
{
    use TracksUsage;

    private const string SOCIAL_ACTIONS_URL = 'https://api.linkedin.com/rest/socialActions';

    public function __construct(private readonly HttpFactory $http) {}

    private function apiVersion(): string
    {
        // Mirrors the publish connector's default versioned-API month.
        return (string) config('services.linkedin-openid.api_version', '202605');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'LinkedIn-Version' => $this->apiVersion(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        $shareUrn = $target->remote_ids[0] ?? $target->remote_id;

        if ($shareUrn === null) {
            return ReplyFetchResult::failed('Target has no remote id.');
        }

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->get(self::SOCIAL_ACTIONS_URL.'/'.rawurlencode($shareUrn).'/comments', ['count' => 50]);
        } catch (ConnectionException $e) {
            return ReplyFetchResult::failed($e->getMessage());
        }

        // LinkedIn 404s a post that simply has no comments yet — a routine success, not a failed read.
        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, $response, succeeded: $response->successful() || $response->status() === 404);

        // LinkedIn returns 404 for a post that simply has no comments yet.
        if ($response->status() === 404) {
            return ReplyFetchResult::ok([]);
        }

        if ($response->failed()) {
            return $this->mapFetchFailure($response);
        }

        $ownerActor = 'urn:li:person:'.$account->remote_account_id;

        $replies = [];
        foreach ((array) $response->json('elements', []) as $element) {
            $actor = (string) ($element['actor'] ?? '');
            $commentUrn = (string) ($element['commentUrn'] ?? '');

            if ($commentUrn === '' || $actor === $ownerActor) {
                continue;
            }

            $createdMs = (int) ($element['created']['time'] ?? 0);
            $createdAt = $createdMs > 0
                ? CarbonImmutable::createFromTimestampMs($createdMs)
                : CarbonImmutable::now();

            if ($since !== null && ! $createdAt->greaterThan($since)) {
                continue;
            }

            $replies[] = new FetchedReply(
                remoteReplyId: $commentUrn,
                remoteCid: null,
                parentRemoteId: (string) ($element['parentComment'] ?? $shareUrn),
                authorHandle: $this->urnId($actor),
                authorName: null,
                authorAvatarUrl: null,
                text: (string) ($element['message']['text'] ?? ''),
                remoteCreatedAt: $createdAt,
            );
        }

        return ReplyFetchResult::ok($replies);
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials, array $media = []): ReplyPostResult
    {
        if ($media !== []) {
            return ReplyPostResult::failed('LinkedIn comments cannot include attachments.');
        }

        $object = $this->objectUrnFor($parent);

        if ($object === null) {
            return ReplyPostResult::failed('Could not resolve the LinkedIn post for this comment.');
        }

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->post(self::SOCIAL_ACTIONS_URL.'/'.rawurlencode($object).'/comments', [
                    'actor' => 'urn:li:person:'.$account->remote_account_id,
                    'object' => $object,
                    'message' => ['text' => $text],
                    'parentComment' => $parent->remote_reply_id,
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

        $commentUrn = (string) $response->json('commentUrn', '');

        return ReplyPostResult::ok($commentUrn !== '' ? $commentUrn : (string) $response->header('x-restli-id'));
    }

    public function likeReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        $actor = 'urn:li:person:'.$account->remote_account_id;

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->post(self::SOCIAL_ACTIONS_URL.'/'.rawurlencode($reply->remote_reply_id).'/likes', [
                    'actor' => $actor,
                    'object' => $reply->remote_reply_id,
                ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_LIKE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    public function unlikeReply(ConnectedAccount $account, PostTargetReply $reply, ?string $likeRemoteId, array $credentials): ReplyActionResult
    {
        $actor = 'urn:li:person:'.$account->remote_account_id;

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->delete(self::SOCIAL_ACTIONS_URL.'/'.rawurlencode($reply->remote_reply_id).'/likes/'.rawurlencode($actor));
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_UNLIKE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    public function deleteReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        $object = $this->objectUrnFor($reply);
        $commentId = $this->commentId($reply->remote_reply_id);

        if ($object === null || $commentId === null) {
            return ReplyActionResult::failed('Could not resolve the LinkedIn comment to delete.');
        }

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->delete(self::SOCIAL_ACTIONS_URL.'/'.rawurlencode($object).'/comments/'.rawurlencode($commentId), [
                    'actor' => 'urn:li:person:'.$account->remote_account_id,
                ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_DELETE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    /** The numeric id segment of a comment URN `urn:li:comment:(OBJECT,ID)`. */
    private function commentId(string $commentUrn): ?string
    {
        if (! str_starts_with($commentUrn, 'urn:li:comment:(')) {
            return null;
        }

        $inner = substr($commentUrn, strlen('urn:li:comment:('), -1);
        $parts = explode(',', $inner, 2);
        $id = $parts[1] ?? '';

        return $id !== '' ? $id : null;
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

    /**
     * The share/ugcPost/activity URN a reply belongs to: the parent's
     * `parent_remote_id` when it is already a post URN (top-level comment), else
     * parsed out of the parent comment URN `urn:li:comment:(OBJECT,ID)`.
     */
    private function objectUrnFor(PostTargetReply $parent): ?string
    {
        if ($parent->parent_remote_id !== null && ! str_starts_with($parent->parent_remote_id, 'urn:li:comment:')) {
            return $parent->parent_remote_id;
        }

        if (! str_starts_with($parent->remote_reply_id, 'urn:li:comment:(')) {
            return null;
        }

        $inner = substr($parent->remote_reply_id, strlen('urn:li:comment:('), -1);
        $object = explode(',', $inner, 2)[0];

        return $object !== '' ? $object : null;
    }

    /** The trailing id segment of a URN, e.g. `urn:li:person:ABC` → `ABC`. */
    private function urnId(string $urn): string
    {
        // explode() always yields at least one element, so the last index exists.
        $segments = explode(':', $urn);
        $last = $segments[count($segments) - 1];

        return $last !== '' ? $last : $urn;
    }

    private function mapFetchFailure(Response $response): ReplyFetchResult
    {
        return match (true) {
            $response->status() === 401 => ReplyFetchResult::authExpired($this->excerpt($response)),
            $response->status() === 403 => ReplyFetchResult::unsupported($this->excerpt($response)),
            $response->status() === 429 => ReplyFetchResult::rateLimited($this->excerpt($response)),
            default => ReplyFetchResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('message') ?? mb_substr($response->body(), 0, 200));
    }
}
