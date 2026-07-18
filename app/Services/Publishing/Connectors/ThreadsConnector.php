<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\ImageConversionFailed;
use App\Services\Media\PublicMediaUrl;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Publishes to Threads via the same async two-step container flow as Instagram
 * (create a media container, poll it, then publish the container), but adds
 * reply-chain threading: each non-empty segment of the post becomes its own
 * Threads post, chained to the previously published post via `reply_to_id`.
 *
 * Threads has no direct byte-upload API — image/video containers reference a
 * public HTTPS URL that Meta fetches server-side (see PublicMediaUrl). Post
 * media is only attached to the first segment; later segments are text replies.
 */
class ThreadsConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string BASE_URL = 'https://graph.threads.net/v1.0';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicMediaUrl $publicMediaUrl,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Threads access token unavailable; reconnect the account.');
        }

        $threadsUserId = (string) $context->account->remote_account_id;

        $segments = array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            // A caption-less media post is valid on Threads (an IMAGE/VIDEO/CAROUSEL
            // container with empty text), matching Facebook/Instagram — only a post
            // with neither text nor media is a real error.
            if ($context->media === []) {
                return PublishResult::failure(ErrorKind::Validation, 'Threads requires text or media');
            }

            $segments = [''];
        }

        $remoteIds = $context->target->remote_ids ?? [];
        $state = new MediaUploadState($context->target->media_upload_state);

        try {
            foreach ($segments as $index => $text) {
                // Resume: skip segments already published on a prior attempt.
                if (isset($remoteIds[$index])) {
                    continue;
                }

                $replyToId = $index > 0 ? ($remoteIds[$index - 1] ?? null) : null;
                $media = $index === 0 ? $context->media : [];

                $containerId = $this->resolveContainerId($context, $state, $threadsUserId, $index, $text, $media, $replyToId, $token);

                $notReady = $this->pollContainer($context, $containerId, $token);
                if ($notReady !== null) {
                    return $notReady;
                }

                $publish = $this->http->asForm()->post(self::BASE_URL.'/'.$threadsUserId.'/threads_publish', [
                    'creation_id' => $containerId,
                    'access_token' => $token,
                ]);

                $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $publish);

                if ($publish->failed()) {
                    return $this->mapFailure($publish);
                }

                $publishedId = (string) $publish->json('id');

                if ($publishedId === '') {
                    return PublishResult::failure(ErrorKind::ServerError, 'Threads did not return a media id');
                }

                $remoteIds[$index] = $publishedId;

                // Persist this segment's id BEFORE sending the next one so a mid-thread
                // death resumes (rather than re-posts) the already-published segments.
                $context->target->forceFill([
                    'remote_id' => $remoteIds[0],
                    'remote_ids' => array_values($remoteIds),
                ])->save();
            }
        } catch (ThreadsRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ImageConversionFailed $e) {
            // The image can't be re-encoded to a format Threads accepts; retrying
            // won't change that, so fail with the reason rather than looping.
            return PublishResult::failure(ErrorKind::Unsupported, $e->getMessage());
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success(array_values($remoteIds));
    }

    /**
     * Create (or resume) the container for a single segment: a TEXT/IMAGE/VIDEO
     * container, or a CAROUSEL parent referencing per-item child containers.
     *
     * @param  list<PostMedia>  $media
     */
    private function resolveContainerId(
        PublishContext $context,
        MediaUploadState $state,
        string $threadsUserId,
        int $index,
        string $text,
        array $media,
        ?string $replyToId,
        string $token,
    ): string {
        $key = $this->containerKey($index);
        $existing = $state->remoteRef($key);

        if ($existing !== null) {
            return $existing;
        }

        $media = array_slice($media, 0, Platform::Threads->maxMedia());

        $containerId = match (count($media)) {
            0 => $this->createTextContainer($context, $threadsUserId, $text, $replyToId, $token),
            1 => $this->createSingleMediaContainer($context, $media[0], $threadsUserId, $text, $replyToId, $token),
            default => $this->createCarouselContainer($context, $state, $media, $threadsUserId, $text, $replyToId, $token),
        };

        $state->markUploaded($key, $containerId);
        $this->persistState($context, $state);

        return $containerId;
    }

    private function containerKey(int $index): string
    {
        return "segment-{$index}";
    }

    private function createTextContainer(PublishContext $context, string $threadsUserId, string $text, ?string $replyToId, string $token): string
    {
        $body = [
            'media_type' => 'TEXT',
            'text' => $text,
            'access_token' => $token,
        ];

        if ($replyToId !== null) {
            $body['reply_to_id'] = $replyToId;
        }

        return $this->createContainer($context, $threadsUserId, $body);
    }

    private function createSingleMediaContainer(PublishContext $context, PostMedia $media, string $threadsUserId, string $text, ?string $replyToId, string $token): string
    {
        $body = [
            'media_type' => $media->isVideo() ? 'VIDEO' : 'IMAGE',
            'text' => $text,
            'access_token' => $token,
        ];
        $body[$media->isVideo() ? 'video_url' : 'image_url'] = $this->publicMediaUrl->for($media, Platform::Threads);

        if ($replyToId !== null) {
            $body['reply_to_id'] = $replyToId;
        }

        return $this->createContainer($context, $threadsUserId, $body);
    }

    /**
     * Create each unpublished carousel-item child container (resuming any already
     * persisted from a prior attempt), then the CAROUSEL parent referencing them.
     *
     * @param  list<PostMedia>  $media
     */
    private function createCarouselContainer(PublishContext $context, MediaUploadState $state, array $media, string $threadsUserId, string $text, ?string $replyToId, string $token): string
    {
        $childIds = [];

        foreach ($media as $item) {
            $childId = $state->remoteRef($item->id);

            if ($childId === null) {
                $childId = $this->createChildContainer($context, $item, $threadsUserId, $token);
                $state->markUploaded($item->id, $childId);
                $this->persistState($context, $state);
            }

            $childIds[] = $childId;
        }

        $body = [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'text' => $text,
            'access_token' => $token,
        ];

        if ($replyToId !== null) {
            $body['reply_to_id'] = $replyToId;
        }

        return $this->createContainer($context, $threadsUserId, $body);
    }

    private function createChildContainer(PublishContext $context, PostMedia $media, string $threadsUserId, string $token): string
    {
        $body = [
            'is_carousel_item' => 'true',
            'media_type' => $media->isVideo() ? 'VIDEO' : 'IMAGE',
            'access_token' => $token,
        ];
        $body[$media->isVideo() ? 'video_url' : 'image_url'] = $this->publicMediaUrl->for($media, Platform::Threads);

        return $this->createContainer($context, $threadsUserId, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function createContainer(PublishContext $context, string $threadsUserId, array $body): string
    {
        $response = $this->http->asForm()->post(self::BASE_URL.'/'.$threadsUserId.'/threads', $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new ThreadsRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    private function persistState(PublishContext $context, MediaUploadState $state): void
    {
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }

    /**
     * Poll the container's processing status. Returns null when it is FINISHED (ready
     * to publish), or a PublishResult to return immediately (MediaProcessing to retry,
     * or a terminal failure) otherwise.
     */
    private function pollContainer(PublishContext $context, string $containerId, string $token): ?PublishResult
    {
        $response = $this->http->get(self::BASE_URL.'/'.$containerId, [
            'fields' => 'status',
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        if ($response->failed()) {
            return $this->mapFailure($response);
        }

        $status = (string) $response->json('status');

        return match ($status) {
            'FINISHED', 'PUBLISHED' => null,
            'IN_PROGRESS' => PublishResult::failure(ErrorKind::MediaProcessing, 'Threads is processing the media.', retryAfter: 6),
            default => PublishResult::failure(
                ErrorKind::ServerError,
                "Threads container processing failed ({$status}).",
                $response->status(),
                $this->excerpt($response),
            ),
        };
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $ids = $target->remote_ids ?? array_filter([$target->remote_id]);

        if ($ids === []) {
            return;
        }

        if ($token === '') {
            throw new RuntimeException('Threads access token unavailable; reconnect the account.');
        }

        foreach ($ids as $id) {
            // Best-effort per post in the chain: swallow a 4xx (already deleted /
            // unsupported) rather than failing the whole delete flow over it.
            $response = $this->http->delete(self::BASE_URL.'/'.$id, ['access_token' => $token]);

            $succeeded = $response->successful() || $response->status() === 404 || $response->clientError();

            $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $succeeded);
        }
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('error.message') ?? 'Threads request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}

/**
 * Internal signal so a failed container-create call short-circuits to the shared
 * HTTP-error mapping. Not part of the public connector surface.
 *
 * @internal
 */
final class ThreadsRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Threads request failed.');
    }
}
