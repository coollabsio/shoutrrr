<?php

declare(strict_types=1);

namespace App\Services\Engagement\Connectors;

use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\EngagementStatus;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\BatchEngagementConnector;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Engagement\RetryAfter;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class XEngagementConnector implements BatchEngagementConnector, EngagementConnector
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    private const string MEDIA_BASE = 'https://api.x.com/2/media/upload';

    private const int APPEND_CHUNK = 4 * 1024 * 1024;

    private const int STATUS_POLL_MAX = 60;

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        $rootId = $target->remote_ids[0] ?? $target->remote_id;

        if ($rootId === null) {
            return ReplyFetchResult::failed('Target has no remote id.');
        }

        // Stored X handles carry a leading '@' (see ConnectedAccountData::resolveHandle),
        // but the search `from:` operator requires a bare username — an '@' makes the
        // whole query invalid (HTTP 400) and no replies are ever returned.
        $handle = ltrim((string) $account->handle, '@');

        $query = $handle === ''
            ? "conversation_id:{$rootId}"
            : "conversation_id:{$rootId} -from:{$handle}";

        $params = [
            'query' => $query,
            'tweet.fields' => 'author_id,created_at,in_reply_to_user_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 100,
        ];

        if ($since !== null) {
            $params['start_time'] = $since->toIso8601ZuluString();
        }

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/tweets/search/recent', $params);
        } catch (ConnectionException $e) {
            return ReplyFetchResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, $response);

        if ($response->failed()) {
            return $this->mapFetchFailure($response);
        }

        $users = $this->indexUsers($response);

        $replies = [];
        foreach ((array) $response->json('data', []) as $tweet) {
            $replies[] = $this->toFetchedReply($tweet, $users, (string) $rootId);
        }

        return ReplyFetchResult::ok($replies);
    }

    /**
     * Fetch replies for several of the account's posts in one search call by
     * OR-combining their conversation ids (chunked to stay under X's query-length
     * limit), then bucketing the returned tweets back to each root by the
     * tweet's own `conversation_id`.
     *
     * @param  list<string>  $rootIds
     * @param  array<string, mixed>  $credentials
     * @return array<string, ReplyFetchResult>
     */
    public function fetchRepliesForConversations(ConnectedAccount $account, array $rootIds, array $credentials, ?CarbonImmutable $since): array
    {
        $rootIds = array_values(array_unique(array_filter(
            array_map(fn ($id): string => (string) $id, $rootIds),
            fn (string $id): bool => $id !== '',
        )));

        /** @var array<string, ReplyFetchResult> $results */
        $results = [];
        foreach ($rootIds as $id) {
            // Default: no replies. Matched tweets and per-chunk failures overwrite this.
            $results[$id] = ReplyFetchResult::ok([]);
        }

        if ($rootIds === []) {
            return $results;
        }

        $handle = ltrim((string) $account->handle, '@');
        $token = (string) ($credentials['access_token'] ?? '');

        $chunks = $this->chunkByQueryBudget($rootIds, $handle);

        foreach ($chunks as $index => $chunk) {
            [$byConversation, $failure] = $this->fetchChunkPages($account, $chunk, $handle, $token, $since);

            if ($failure !== null) {
                $this->assignToChunk($results, $chunk, $failure);

                // Rate-limit / auth failures are account-wide (shared token): stop
                // hammering the API and propagate to every remaining chunk.
                if (in_array($failure->status, [EngagementStatus::RateLimited, EngagementStatus::AuthExpired], true)) {
                    foreach (array_slice($chunks, $index + 1) as $remaining) {
                        $this->assignToChunk($results, $remaining, $failure);
                    }

                    break;
                }

                continue;
            }

            foreach ($byConversation as $conversationId => $replies) {
                $results[$conversationId] = ReplyFetchResult::ok($replies);
            }
        }

        return $results;
    }

    /**
     * Fetch one chunk's replies, following `next_token` pagination up to a bounded
     * page cap so a viral post can't spin the API indefinitely. Any page failure
     * discards the chunk's partial data and returns the failure: the account job
     * leaves `reply_fetched_at` untouched on a non-ok result, so the chunk is simply
     * re-fetched next run rather than persisted half-complete (which could wrongly
     * mark a conversation empty when a later, unfetched page held its replies).
     *
     * @param  list<string>  $chunk
     * @return array{0: array<string, list<FetchedReply>>, 1: ReplyFetchResult|null}
     */
    private function fetchChunkPages(ConnectedAccount $account, array $chunk, string $handle, string $token, ?CarbonImmutable $since): array
    {
        $ors = implode(' OR ', array_map(fn (string $id): string => "conversation_id:{$id}", $chunk));
        $query = $handle === '' ? "({$ors})" : "({$ors}) -from:{$handle}";

        $baseParams = [
            'query' => $query,
            'tweet.fields' => 'author_id,created_at,in_reply_to_user_id,conversation_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 100,
        ];

        if ($since !== null) {
            $baseParams['start_time'] = $since->toIso8601ZuluString();
        }

        $chunkSet = array_flip($chunk);
        $maxPages = max(1, (int) config('engagement.max_search_pages', 5));

        /** @var array<string, list<FetchedReply>> $byConversation */
        $byConversation = [];
        $nextToken = null;
        $page = 0;

        do {
            $params = $baseParams;

            if ($nextToken !== null) {
                $params['next_token'] = $nextToken;
            }

            try {
                $response = $this->http
                    ->withToken($token)
                    ->acceptJson()
                    ->get(self::BASE.'/tweets/search/recent', $params);
            } catch (ConnectionException $e) {
                return [[], ReplyFetchResult::failed($e->getMessage())];
            }

            $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLIES_FETCH, $account, $response);

            if ($response->failed()) {
                return [[], $this->mapFetchFailure($response)];
            }

            $users = $this->indexUsers($response);

            foreach ((array) $response->json('data', []) as $tweet) {
                $conversationId = (string) ($tweet['conversation_id'] ?? '');

                if ($conversationId === '' || ! isset($chunkSet[$conversationId])) {
                    continue;
                }

                $byConversation[$conversationId][] = $this->toFetchedReply($tweet, $users, $conversationId);
            }

            $nextToken = $this->nextToken($response);
            $page++;
        } while ($nextToken !== null && $page < $maxPages);

        if ($nextToken !== null) {
            // More pages remained but we hit the cap. Recent Search is newest-first,
            // so the unfetched overflow is older than what we kept and `since` will
            // advance past it — surface it rather than dropping it silently.
            Log::warning('engagement.fetch.truncated', [
                'platform' => $account->platform->value,
                'account_id' => $account->id,
                'conversations' => $chunk,
                'pages_fetched' => $page,
            ]);
        }

        return [$byConversation, null];
    }

    private function nextToken(Response $response): ?string
    {
        $token = $response->json('meta.next_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array<string, array<string, mixed>>  $users
     */
    private function toFetchedReply(array $tweet, array $users, string $parentRemoteId): FetchedReply
    {
        $author = $users[(string) ($tweet['author_id'] ?? '')] ?? [];

        return new FetchedReply(
            remoteReplyId: (string) $tweet['id'],
            remoteCid: null,
            parentRemoteId: $parentRemoteId,
            authorHandle: (string) ($author['username'] ?? ''),
            authorName: isset($author['name']) ? (string) $author['name'] : null,
            authorAvatarUrl: isset($author['profile_image_url']) ? (string) $author['profile_image_url'] : null,
            text: (string) ($tweet['text'] ?? ''),
            remoteCreatedAt: isset($tweet['created_at']) ? CarbonImmutable::parse((string) $tweet['created_at']) : Date::now(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexUsers(Response $response): array
    {
        $users = [];
        foreach ((array) $response->json('includes.users', []) as $user) {
            $users[(string) $user['id']] = $user;
        }

        return $users;
    }

    /**
     * Split root ids into chunks whose OR-combined query stays under X's Recent
     * Search query-length limit (512 chars for self-serve access; docs.x.com).
     * 480 leaves margin for the parentheses and the `-from:` clause.
     *
     * @param  list<string>  $rootIds
     * @return list<list<string>>
     */
    private function chunkByQueryBudget(array $rootIds, string $handle): array
    {
        $budget = 480 - ($handle === '' ? 2 : mb_strlen(" -from:{$handle}") + 2);

        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($rootIds as $id) {
            $piece = mb_strlen("conversation_id:{$id}");
            $add = ($current === [] ? 0 : 4) + $piece;

            if ($current !== [] && $length + $add > $budget) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
                $add = $piece;
            }

            $current[] = $id;
            $length += $add;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  array<string, ReplyFetchResult>  $results
     * @param  list<string>  $chunk
     */
    private function assignToChunk(array &$results, array $chunk, ReplyFetchResult $result): void
    {
        foreach ($chunk as $id) {
            $results[$id] = $result;
        }
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials, array $media = []): ReplyPostResult
    {
        try {
            $token = (string) ($credentials['access_token'] ?? '');

            $mediaIds = $media === [] ? [] : $this->uploadReplyMedia($account, $media, $token);

            $body = [
                'text' => $text,
                'reply' => ['in_reply_to_tweet_id' => $parent->remote_reply_id],
            ];

            if ($mediaIds !== []) {
                $body['media'] = ['media_ids' => $mediaIds];
            }

            $response = $this->http
                ->withToken($token)
                ->acceptJson()
                ->post(self::BASE.'/tweets', $body);
        } catch (XReplyMediaFailed $e) {
            return ReplyPostResult::failed($this->excerpt($e->response));
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

        return ReplyPostResult::ok((string) $response->json('data.id'));
    }

    public function likeReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        try {
            $response = $this->http->withToken((string) ($credentials['access_token'] ?? ''))->acceptJson()
                ->post(self::BASE.'/users/'.$account->remote_account_id.'/likes', [
                    'tweet_id' => $reply->remote_reply_id,
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
            $response = $this->http->withToken((string) ($credentials['access_token'] ?? ''))->acceptJson()
                ->delete(self::BASE.'/users/'.$account->remote_account_id.'/likes/'.$reply->remote_reply_id);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::REPLY_UNLIKE, $account, $response);

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    public function deleteReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        try {
            $response = $this->http->withToken((string) ($credentials['access_token'] ?? ''))->acceptJson()
                ->delete(self::BASE.'/tweets/'.$reply->remote_reply_id);
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

    /**
     * @param  list<PostMedia>  $media
     * @return list<string>
     */
    private function uploadReplyMedia(ConnectedAccount $account, array $media, string $token): array
    {
        $videoMedia = array_values(array_filter($media, fn (PostMedia $m): bool => $m->isVideo()));

        if ($videoMedia !== []) {
            return [$this->uploadVideoChunks($account, $videoMedia[0], $token)];
        }

        return $this->uploadImages($account, $media, $token);
    }

    /**
     * @param  list<PostMedia>  $media
     * @return list<string>
     */
    private function uploadImages(ConnectedAccount $account, array $media, string $token): array
    {
        $ids = [];

        foreach ($media as $item) {
            $bytes = Storage::disk($item->disk)->get($item->path);
            $response = $this->http
                ->withToken($token)
                ->asMultipart()
                ->attach('media', (string) $bytes, 'upload')
                ->post(self::MEDIA_BASE, ['media_category' => 'tweet_image']);

            $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_UPLOAD, $account, $response);

            if ($response->failed()) {
                throw new XReplyMediaFailed($response);
            }

            $ids[] = (string) $response->json('data.id');
        }

        return $ids;
    }

    private function uploadVideoChunks(ConnectedAccount $account, PostMedia $media, string $token): string
    {
        $disk = Storage::disk($media->disk);
        $total = (int) $disk->size($media->path);

        $init = $this->http->withToken($token)->acceptJson()
            ->post(self::MEDIA_BASE.'/initialize', [
                'media_type' => 'video/mp4',
                'total_bytes' => $total,
                'media_category' => 'tweet_video',
            ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_UPLOAD, $account, $init);

        if ($init->failed()) {
            throw new XReplyMediaFailed($init);
        }

        $mediaId = (string) $init->json('data.id');

        $stream = $disk->readStream($media->path);

        try {
            $segmentIndex = 0;
            while (! feof($stream)) {
                $segment = fread($stream, self::APPEND_CHUNK);
                if ($segment === false || $segment === '') {
                    break;
                }
                $append = $this->http->withToken($token)->asMultipart()
                    ->attach('media', $segment, 'chunk')
                    ->post(self::MEDIA_BASE.'/'.$mediaId.'/append', ['segment_index' => $segmentIndex]);

                $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_UPLOAD, $account, $append);

                if ($append->failed()) {
                    throw new XReplyMediaFailed($append);
                }
                $segmentIndex++;
            }
        } finally {
            fclose($stream);
        }

        $finalize = $this->http->withToken($token)->acceptJson()
            ->post(self::MEDIA_BASE.'/'.$mediaId.'/finalize');

        $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_UPLOAD, $account, $finalize);

        if ($finalize->failed()) {
            throw new XReplyMediaFailed($finalize);
        }

        // Poll STATUS until the video is ready (bounded to avoid infinite loops).
        $status = $finalize;
        for ($i = 0; $i < self::STATUS_POLL_MAX; $i++) {
            $status = $this->http->withToken($token)->acceptJson()
                ->get(self::MEDIA_BASE, ['command' => 'STATUS', 'media_id' => $mediaId]);

            $this->meter(UsageCategory::ExternalApi, UsageOperation::MEDIA_STATUS_POLL, $account, $status);

            if ($status->failed()) {
                throw new XReplyMediaFailed($status);
            }

            /** @var array<string, mixed> $info */
            $info = (array) $status->json('data.processing_info', []);
            $state = (string) ($info['state'] ?? 'succeeded');

            if ($state === 'succeeded') {
                return $mediaId;
            }

            if ($state === 'failed') {
                throw new XReplyMediaFailed($status);
            }

            // Still transcoding — wait before re-polling. Honour X's own hint
            // (`check_after_secs`) so the loop doesn't burn all 60 iterations
            // before the video is ready. Sleep at the END so a first-poll
            // "succeeded" returns instantly (and tests stay fast).
            $waitSeconds = max(1, (int) $status->json('data.processing_info.check_after_secs', 2));
            usleep($waitSeconds * 1_000_000);
        }

        throw new XReplyMediaFailed($status);
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
        return (string) ($response->json('title') ?? $response->json('detail') ?? mb_substr($response->body(), 0, 200));
    }
}

/**
 * Internal signal so a failed reply media upload short-circuits to a ReplyPostResult::failed
 * without pushing an empty media id. Not part of the public connector surface.
 *
 * @internal
 */
final class XReplyMediaFailed extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('X reply media upload failed.');
    }
}
