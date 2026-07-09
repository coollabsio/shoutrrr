<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\UsageCategory;
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
use RuntimeException;

class FacebookConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ImageCompressor $imageCompressor,
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
            return PublishResult::failure(ErrorKind::AuthExpired, 'Facebook Page access token unavailable; reconnect the account.');
        }

        if (($context->target->remote_id ?? null) !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id]);
        }

        $pageId = (string) $context->account->remote_account_id;
        $text = implode("\n\n", array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        )));

        $videoMedia = array_values(array_filter($context->media, fn (PostMedia $m): bool => $m->isVideo()));

        // TODO(Task 5): native video publishing via the resumable /{page-id}/videos
        // chunked upload protocol. Until then, refuse rather than silently drop media.
        if ($videoMedia !== []) {
            return PublishResult::failure(ErrorKind::Validation, 'Facebook video publishing not yet implemented');
        }

        $images = array_slice($context->media, 0, Platform::Facebook->maxMedia());

        try {
            if (count($images) === 1) {
                $response = $this->publishSinglePhoto($pageId, $text, $images[0], $token);
            } elseif (count($images) > 1) {
                $response = $this->publishCarousel($pageId, $text, $images, $token, $context);
            } else {
                $response = $this->publishFeed($pageId, $text, $token);
            }

            $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $response);

            if ($response->failed()) {
                return $this->mapFailure($response);
            }

            $id = count($images) === 1
                ? (string) ($response->json('post_id') ?? $response->json('id'))
                : (string) $response->json('id');
        } catch (FacebookRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        if ($id === '') {
            return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not return a post id');
        }

        return PublishResult::success([$id]);
    }

    private function publishFeed(string $pageId, string $text, string $token): Response
    {
        $body = ['message' => $text, 'access_token' => $token];

        $link = $this->firstUrl($text);
        if ($link !== null) {
            $body['link'] = $link;
        }

        return $this->http->asForm()->post($this->baseUrl().'/'.$pageId.'/feed', $body);
    }

    private function publishSinglePhoto(string $pageId, string $text, PostMedia $media, string $token): Response
    {
        $bytes = (string) Storage::disk($media->disk)->get($media->path);
        $compressed = $this->imageCompressor->compressToFit($bytes, Platform::Facebook->maxMediaBytes(), $media->mime, Platform::Facebook->allowedMime());

        return $this->http
            ->asMultipart()
            ->attach('source', $compressed->bytes, basename($media->path))
            ->post($this->baseUrl().'/'.$pageId.'/photos', [
                'caption' => $text,
                'published' => 'true',
                'access_token' => $token,
            ]);
    }

    /**
     * Upload each image unpublished, then create the feed post referencing every
     * uploaded asset via indexed `attached_media[i]` JSON-string form fields.
     *
     * @param  list<PostMedia>  $media
     */
    private function publishCarousel(string $pageId, string $text, array $media, string $token, PublishContext $context): Response
    {
        $attachedMedia = [];

        foreach ($media as $index => $item) {
            $bytes = (string) Storage::disk($item->disk)->get($item->path);
            $compressed = $this->imageCompressor->compressToFit($bytes, Platform::Facebook->maxMediaBytes(), $item->mime, Platform::Facebook->allowedMime());

            $upload = $this->http
                ->asMultipart()
                ->attach('source', $compressed->bytes, basename($item->path))
                ->post($this->baseUrl().'/'.$pageId.'/photos?published=false&temporary=true', [
                    'access_token' => $token,
                ]);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $upload);

            if ($upload->failed()) {
                throw new FacebookRequestFailed($upload);
            }

            $attachedMedia[$index] = (string) $upload->json('id');
        }

        $body = ['message' => $text, 'access_token' => $token];
        foreach ($attachedMedia as $index => $mediaFbid) {
            $body["attached_media[{$index}]"] = json_encode(['media_fbid' => $mediaFbid]);
        }

        return $this->http->asForm()->post($this->baseUrl().'/'.$pageId.'/feed', $body);
    }

    private function firstUrl(string $text): ?string
    {
        if (! preg_match('~https?://[^\s<>"\']+~i', $text, $matches)) {
            return null;
        }

        return rtrim($matches[0], '.,!?)]}');
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $id = $target->remote_id;

        if ($id === null) {
            return;
        }

        if ($token === '') {
            throw new RuntimeException('Facebook Page access token unavailable; reconnect the account.');
        }

        $response = $this->http->delete($this->baseUrl().'/'.$id, ['access_token' => $token]);

        // A 404 means the post is already gone — throwUnlessDeleteAccepted treats it as done.
        $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $response->successful() || $response->status() === 404);

        $this->throwUnlessDeleteAccepted($response);
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('error.message') ?? 'Facebook request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}

/**
 * Internal signal so a failed carousel image upload short-circuits to the shared
 * HTTP-error mapping. Not part of the public connector surface.
 *
 * @internal
 */
final class FacebookRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Facebook request failed.');
    }
}
