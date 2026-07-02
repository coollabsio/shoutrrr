<?php

declare(strict_types=1);

namespace App\Services\Engagement\Connectors;

use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class XEngagementConnector implements EngagementConnector
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

        $query = "conversation_id:{$rootId} -from:{$account->handle}";

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

        /** @var array<string, array<string, mixed>> $users */
        $users = [];
        foreach ((array) $response->json('includes.users', []) as $user) {
            $users[(string) $user['id']] = $user;
        }

        $replies = [];
        foreach ((array) $response->json('data', []) as $tweet) {
            $author = $users[(string) ($tweet['author_id'] ?? '')] ?? [];

            $replies[] = new FetchedReply(
                remoteReplyId: (string) $tweet['id'],
                remoteCid: null,
                parentRemoteId: (string) $rootId,
                authorHandle: (string) ($author['username'] ?? ''),
                authorName: isset($author['name']) ? (string) $author['name'] : null,
                authorAvatarUrl: isset($author['profile_image_url']) ? (string) $author['profile_image_url'] : null,
                text: (string) ($tweet['text'] ?? ''),
                remoteCreatedAt: isset($tweet['created_at']) ? CarbonImmutable::parse((string) $tweet['created_at']) : Date::now(),
            );
        }

        return ReplyFetchResult::ok($replies);
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
            $response->status() === 429 => ReplyFetchResult::rateLimited($this->excerpt($response)),
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
