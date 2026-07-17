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
 * Publishes to Instagram via the async two-step container flow: create a media
 * container (image / carousel-of-children / Reels video), poll it until Meta
 * finishes fetching + processing the referenced URL, then publish the container.
 *
 * Instagram has no direct byte-upload API — every container references a public
 * HTTPS URL that Meta fetches server-side (see PublicMediaUrl). Media is required;
 * there is no text-only post type.
 */
class InstagramConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    /** The pseudo "media id" used to key the top-level (single or carousel-parent) container in MediaUploadState. */
    private const string CONTAINER_KEY = 'container';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicMediaUrl $publicMediaUrl,
    ) {}

    private function apiVersion(): string
    {
        return (string) config('services.facebook.graph_version');
    }

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', $this->apiVersion());
    }

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'Instagram access token unavailable; reconnect the account.');
        }

        if (($context->target->remote_id ?? null) !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id]);
        }

        if ($context->media === []) {
            return PublishResult::failure(ErrorKind::Validation, 'Instagram requires at least one image or video');
        }

        $igUserId = (string) $context->account->remote_account_id;
        $caption = implode("\n\n", array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        )));

        $state = new MediaUploadState($context->target->media_upload_state);

        try {
            $containerId = $this->resolveContainerId($context, $state, $igUserId, $caption, $token);

            $notReady = $this->pollContainer($context, $containerId, $token);
            if ($notReady !== null) {
                return $notReady;
            }

            $publish = $this->http->asForm()->post($this->baseUrl().'/'.$igUserId.'/media_publish', [
                'creation_id' => $containerId,
                'access_token' => $token,
            ]);

            $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $publish);

            if ($publish->failed()) {
                return $this->mapFailure($publish);
            }

            $mediaId = (string) $publish->json('id');
        } catch (InstagramRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ImageConversionFailed $e) {
            // The image can't be re-encoded to the JPEG Instagram requires; retrying
            // won't change that, so fail with the reason rather than looping.
            return PublishResult::failure(ErrorKind::Unsupported, $e->getMessage());
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        if ($mediaId === '') {
            return PublishResult::failure(ErrorKind::ServerError, 'Instagram did not return a media id');
        }

        return PublishResult::success([$mediaId]);
    }

    /**
     * Create (or resume) the container that will be handed to media_publish: a single
     * image/Reels container, or a CAROUSEL parent referencing per-item child containers.
     */
    private function resolveContainerId(PublishContext $context, MediaUploadState $state, string $igUserId, string $caption, string $token): string
    {
        $existing = $state->remoteRef(self::CONTAINER_KEY);

        if ($existing !== null) {
            return $existing;
        }

        $media = array_slice($context->media, 0, Platform::Instagram->maxMedia());

        $containerId = count($media) === 1
            ? $this->createSingleContainer($context, $media[0], $igUserId, $caption, $token)
            : $this->createCarouselContainer($context, $state, $media, $igUserId, $caption, $token);

        $state->markUploaded(self::CONTAINER_KEY, $containerId);
        $this->persistState($context, $state);

        return $containerId;
    }

    private function createSingleContainer(PublishContext $context, PostMedia $media, string $igUserId, string $caption, string $token): string
    {
        $body = [
            'caption' => $caption,
            'access_token' => $token,
        ];

        if ($media->isVideo()) {
            $body['media_type'] = 'REELS';
            $body['video_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);
        } else {
            $body['image_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);
        }

        $response = $this->http->asForm()->post($this->baseUrl().'/'.$igUserId.'/media', $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    /**
     * Create each unpublished carousel-item child container (resuming any already
     * persisted from a prior attempt), then the CAROUSEL parent referencing them.
     *
     * @param  list<PostMedia>  $media
     */
    private function createCarouselContainer(PublishContext $context, MediaUploadState $state, array $media, string $igUserId, string $caption, string $token): string
    {
        $childIds = [];

        foreach ($media as $item) {
            $childId = $state->remoteRef($item->id);

            if ($childId === null) {
                $childId = $this->createChildContainer($context, $item, $igUserId, $token);
                $state->markUploaded($item->id, $childId);
                $this->persistState($context, $state);
            }

            $childIds[] = $childId;
        }

        $response = $this->http->asForm()->post($this->baseUrl().'/'.$igUserId.'/media', [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
        }

        return (string) $response->json('id');
    }

    private function createChildContainer(PublishContext $context, PostMedia $media, string $igUserId, string $token): string
    {
        $body = [
            'is_carousel_item' => 'true',
            'access_token' => $token,
        ];
        $body[$media->isVideo() ? 'video_url' : 'image_url'] = $this->publicMediaUrl->for($media, Platform::Instagram);

        $response = $this->http->asForm()->post($this->baseUrl().'/'.$igUserId.'/media', $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        if ($response->failed()) {
            throw new InstagramRequestFailed($response);
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
        $response = $this->http->get($this->baseUrl().'/'.$containerId, [
            'fields' => 'status_code',
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        if ($response->failed()) {
            return $this->mapFailure($response);
        }

        $status = (string) $response->json('status_code');

        return match ($status) {
            'FINISHED', 'PUBLISHED' => null,
            'IN_PROGRESS' => PublishResult::failure(ErrorKind::MediaProcessing, 'Instagram is processing the media.', retryAfter: 6),
            default => PublishResult::failure(
                ErrorKind::ServerError,
                "Instagram container processing failed ({$status}).",
                $response->status(),
                $this->excerpt($response),
            ),
        };
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $id = $target->remote_id;

        if ($id === null) {
            return;
        }

        if ($token === '') {
            throw new RuntimeException('Instagram access token unavailable; reconnect the account.');
        }

        // IG media deletion is generally unsupported via the Graph API; best-effort the
        // call and swallow a 4xx (matches how the API responds to unsupported deletes)
        // rather than failing the whole delete flow over it.
        $response = $this->http->delete($this->baseUrl().'/'.$id, ['access_token' => $token]);

        $succeeded = $response->successful() || $response->status() === 404 || $response->clientError();

        $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $succeeded);
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('error.message') ?? 'Instagram request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}

/**
 * Internal signal so a failed container-create call short-circuits to the shared
 * HTTP-error mapping. Not part of the public connector surface.
 *
 * @internal
 */
final class InstagramRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Instagram request failed.');
    }
}
