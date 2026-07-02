<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\ImageCompressor;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

class XConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string TWEETS_URL = 'https://api.twitter.com/2/tweets';

    // v2 media upload (the v1.1 upload.twitter.com endpoint was deprecated 2025-03-31).
    // Simple single-request upload is sufficient for images; chunking is only required
    // for video/large media. Requires the OAuth2 `media.write` scope (see Platform::X).
    private const string MEDIA_URL = 'https://api.x.com/2/media/upload';

    private const string MEDIA_BASE = 'https://api.x.com/2/media/upload';

    private const int APPEND_CHUNK = 4 * 1024 * 1024;

    private const int GIF_MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ImageCompressor $imageCompressor,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');
        $remoteIds = $context->target->remote_ids ?? [];

        try {
            $videoMedia = array_values(array_filter($context->media, fn (PostMedia $m): bool => $m->isVideo()));
            $gifMedia = array_values(array_filter(
                $context->media,
                fn (PostMedia $m): bool => ! $m->isVideo() && $m->mime === 'image/gif',
            ));

            if ($videoMedia !== []) {
                $ready = $this->ensureVideoReady($context, $videoMedia[0], $token);
                if (! $ready->isSuccessful()) {
                    return $ready;
                }
                $mediaIds = [(string) $ready->remoteIds[0]];
            } elseif ($gifMedia !== []) {
                $ready = $this->ensureGifReady($context, $gifMedia, $token);
                if (! $ready->isSuccessful()) {
                    return $ready;
                }
                $mediaIds = [(string) $ready->remoteIds[0]];
            } else {
                $mediaIds = $this->uploadMedia($context->media, $token, $context->account);
            }

            foreach ($context->segments as $index => $text) {
                // Resume: skip segments already posted on a prior attempt.
                if (isset($remoteIds[$index])) {
                    continue;
                }

                // X rejects an empty `text` field; once media_ids are attached
                // text is optional, so omit it entirely for a media-only post
                // (otherwise the API returns a 400 "Invalid Request").
                $body = $text === '' ? [] : ['text' => $text];

                if ($index === 0 && $mediaIds !== []) {
                    $body['media'] = ['media_ids' => $mediaIds];
                }

                $previous = $remoteIds[$index - 1] ?? null;

                if ($previous !== null) {
                    $body['reply'] = ['in_reply_to_tweet_id' => $previous];
                }

                $response = $this->http
                    ->withToken($token)
                    ->acceptJson()
                    ->post(self::TWEETS_URL, $body);

                $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $response);

                if ($response->failed()) {
                    return $this->mapFailure($response);
                }

                $remoteIds[$index] = (string) $response->json('data.id');

                // Persist this segment's id BEFORE sending the next one so a mid-thread
                // death resumes (rather than re-posts) the already-published segments (spec §4.3).
                $context->target->forceFill([
                    'remote_id' => $remoteIds[0],
                    'remote_ids' => array_values($remoteIds),
                ])->save();
            }
        } catch (XRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success(array_values($remoteIds));
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');

        foreach ($target->remote_ids ?? array_filter([$target->remote_id]) as $id) {
            $response = $this->http->withToken($token)->delete(self::TWEETS_URL.'/'.$id);

            // A 404 means the tweet is already gone — throwUnlessDeleteAccepted treats it as done.
            $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $response->successful() || $response->status() === 404);

            $this->throwUnlessDeleteAccepted($response);
        }
    }

    /**
     * Upload (once) and poll the async transcode. Returns a success PublishResult whose
     * remoteIds[0] is the ready media_id, or a MediaProcessing failure to retry later.
     */
    private function ensureVideoReady(PublishContext $context, PostMedia $media, string $token): PublishResult
    {
        return $this->ensureChunkedMediaReady($context, $media, $token, 'video/mp4', 'tweet_video', 'video');
    }

    /**
     * @param  list<PostMedia>  $media
     */
    private function ensureGifReady(PublishContext $context, array $media, string $token): PublishResult
    {
        if (count($context->media) > 1 || count($media) > 1) {
            return PublishResult::failure(
                ErrorKind::Validation,
                'X supports one GIF per post, and a GIF cannot be mixed with other media.',
            );
        }

        $item = $media[0];
        $size = (int) Storage::disk($item->disk)->size($item->path);

        if ($size > self::GIF_MAX_BYTES) {
            return PublishResult::failure(
                ErrorKind::Validation,
                'X GIF uploads must be 15 MB or smaller.',
            );
        }

        return $this->ensureChunkedMediaReady($context, $item, $token, 'image/gif', 'tweet_gif', 'GIF');
    }

    /**
     * Upload (once) and poll the async media processing state.
     */
    private function ensureChunkedMediaReady(
        PublishContext $context,
        PostMedia $media,
        string $token,
        string $mediaType,
        string $mediaCategory,
        string $label,
    ): PublishResult {
        $state = new MediaUploadState($context->target->media_upload_state);
        $mediaId = $state->remoteRef($media->id);

        try {
            if ($mediaId === null) {
                $mediaId = $this->uploadChunks($media, $token, $mediaType, $mediaCategory, $context->account);
                $state->markUploaded($media->id, $mediaId);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            }

            $status = $this->http->withToken($token)->acceptJson()
                ->get(self::MEDIA_BASE, ['command' => 'STATUS', 'media_id' => $mediaId]);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $status);

            if ($status->failed()) {
                $kind = $this->classifyStatus($status->status());
                if (in_array($kind, [ErrorKind::ServerError, ErrorKind::RateLimited], true)) {
                    // A transient failure to CHECK status is not a publish failure — treat it as
                    // "still processing, try again" so it uses the media-poll budget, not the
                    // 5-attempt publish-failure budget.
                    return PublishResult::failure(
                        ErrorKind::MediaProcessing,
                        "Could not check {$label} processing status; will retry.",
                        retryAfter: $this->retryAfter($status) ?? 6,
                    );
                }

                // Non-transient (auth/validation/etc.) — surface as a real failure.
                return $this->mapFailure($status);
            }

            $info = (array) $status->json('data.processing_info', []);
            $stateName = (string) ($info['state'] ?? 'succeeded');

            if ($stateName === 'failed') {
                return PublishResult::failure(ErrorKind::ServerError, "X failed to process the {$label}.");
            }

            if ($stateName !== 'succeeded') {
                return PublishResult::failure(
                    ErrorKind::MediaProcessing,
                    ucfirst($label).' is still processing on X.',
                    retryAfter: (int) ($info['check_after_secs'] ?? 5),
                );
            }

            return PublishResult::success([(string) $mediaId]);
        } catch (XRequestFailed $e) {
            return $this->mapFailure($e->response);
        }
    }

    private function uploadChunks(PostMedia $media, string $token, string $mediaType, string $mediaCategory, ConnectedAccount $account): string
    {
        $disk = Storage::disk($media->disk);
        $total = (int) $disk->size($media->path);

        $init = $this->http->withToken($token)->acceptJson()
            ->post(self::MEDIA_BASE.'/initialize', [
                'media_type' => $mediaType,
                'total_bytes' => $total,
                'media_category' => $mediaCategory,
            ]);
        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $init);
        if ($init->failed()) {
            throw new XRequestFailed($init);
        }
        $mediaId = (string) $init->json('data.id');

        // Stream the file from disk, holding at most one 4 MB segment in memory.
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
                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $append);
                if ($append->failed()) {
                    throw new XRequestFailed($append);
                }
                $segmentIndex++;
            }
        } finally {
            fclose($stream);
        }

        $finalize = $this->http->withToken($token)->acceptJson()
            ->post(self::MEDIA_BASE.'/'.$mediaId.'/finalize');
        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $finalize);
        if ($finalize->failed()) {
            throw new XRequestFailed($finalize);
        }

        return $mediaId;
    }

    /**
     * @param  list<PostMedia>  $media
     * @return list<string>
     */
    private function uploadMedia(array $media, string $token, ConnectedAccount $account): array
    {
        $ids = [];

        foreach ($media as $item) {
            $bytes = (string) Storage::disk($item->disk)->get($item->path);
            $compressed = $this->imageCompressor->compressToFit($bytes, Platform::X->maxMediaBytes(), $item->mime, Platform::X->allowedMime());
            $response = $this->http
                ->withToken($token)
                ->asMultipart()
                ->attach('media', $compressed->bytes, 'upload')
                ->post(self::MEDIA_URL, ['media_category' => 'tweet_image']);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $response);

            if ($response->failed()) {
                throw new XRequestFailed($response);
            }

            // v2 returns the numeric media id under data.id (v1.1 used media_id_string).
            $ids[] = (string) $response->json('data.id');
        }

        return $ids;
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->isDuplicateContent($response)
            ? ErrorKind::DuplicateContent
            : $this->classifyStatus($response->status());

        $message = (string) ($response->json('title') ?? $response->json('detail') ?? 'X request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }

    /**
     * X returns HTTP 403 for duplicate posts. Detect them via the response body so the
     * job treats them as a terminal DuplicateContent failure rather than a retryable one.
     */
    private function isDuplicateContent(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        $haystacks = array_filter([
            (string) $response->json('detail'),
            (string) $response->json('title'),
        ]);

        /** @var list<array<string, mixed>> $errors */
        $errors = (array) ($response->json('errors') ?? []);

        foreach ($errors as $error) {
            if (isset($error['message'])) {
                $haystacks[] = (string) $error['message'];
            }

            if ((int) ($error['code'] ?? 0) === 187) {
                return true;
            }
        }

        if ((int) ($response->json('code') ?? 0) === 187) {
            return true;
        }

        foreach ($haystacks as $haystack) {
            if (mb_stripos($haystack, 'duplicate') !== false) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Internal signal so a failed media upload short-circuits to the shared HTTP-error
 * mapping without pushing an empty media id. Not part of the public connector surface.
 *
 * @internal
 */
final class XRequestFailed extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('X request failed.');
    }
}
