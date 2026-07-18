<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\ImageCompressor;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use GuzzleHttp\Psr7\Utils;
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

        $format = $context->target->format;

        if ($format === PostFormat::Reels) {
            if ($videoMedia === []) {
                return PublishResult::failure(ErrorKind::Validation, 'Facebook Reels require a video.');
            }

            return $this->publishReel($context, $videoMedia[0], $pageId, $text, $token);
        }

        if ($format === PostFormat::Story) {
            if ($context->media === []) {
                return PublishResult::failure(ErrorKind::Validation, 'Facebook Stories require an image or video.');
            }

            return $this->publishStory($context, $context->media[0], $pageId, $token);
        }

        if ($videoMedia !== []) {
            return $this->publishVideo($context, $videoMedia[0], $pageId, $text, $token);
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

    /**
     * Publish a single video via the native resumable `/{page-id}/videos` chunked
     * protocol: start → transfer (looped, streamed from disk) → finish. Progress
     * (`upload_session_id`/`video_id`/offsets) is persisted on the target after every
     * phase so a retry resumes the same session instead of starting a new one.
     */
    private function publishVideo(PublishContext $context, PostMedia $media, string $pageId, string $text, string $token): PublishResult
    {
        $state = new MediaUploadState($context->target->media_upload_state);
        $videoId = $state->remoteRef($media->id);
        $blob = $state->blob($media->id);
        $sessionId = is_string($blob['upload_session_id'] ?? null) ? $blob['upload_session_id'] : null;

        $disk = Storage::disk($media->disk);
        $totalSize = (int) $disk->size($media->path);
        $url = $this->baseUrl().'/'.$pageId.'/videos';

        try {
            if ($sessionId === null || $videoId === null) {
                $start = $this->http->asForm()->post($url, [
                    'upload_phase' => 'start',
                    'file_size' => $totalSize,
                    'access_token' => $token,
                ]);

                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $start);

                if ($start->failed()) {
                    throw new FacebookRequestFailed($start);
                }

                $sessionId = (string) $start->json('upload_session_id');
                $videoId = (string) $start->json('video_id');
                $startOffset = (int) $start->json('start_offset', 0);
                $endOffset = (int) $start->json('end_offset', 0);

                $state->markUploaded($media->id, $videoId);
                $state->setBlob($media->id, [
                    'upload_session_id' => $sessionId,
                    'start_offset' => $startOffset,
                    'end_offset' => $endOffset,
                ]);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            } else {
                $startOffset = (int) ($blob['start_offset'] ?? 0);
                $endOffset = (int) ($blob['end_offset'] ?? 0);
            }

            // Stream each chunk's byte range from disk; never hold the whole file.
            $stream = $disk->readStream($media->path);
            try {
                while ($startOffset !== $endOffset) {
                    fseek($stream, $startOffset);
                    $chunk = (string) stream_get_contents($stream, $endOffset - $startOffset);

                    $transfer = $this->http
                        ->asMultipart()
                        ->attach('video_file_chunk', $chunk, basename($media->path))
                        ->post($url, [
                            'upload_phase' => 'transfer',
                            'upload_session_id' => $sessionId,
                            'start_offset' => (string) $startOffset,
                            'access_token' => $token,
                        ]);

                    $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $transfer);

                    if ($transfer->failed()) {
                        throw new FacebookRequestFailed($transfer);
                    }

                    $startOffset = (int) $transfer->json('start_offset', $startOffset);
                    $endOffset = (int) $transfer->json('end_offset', $endOffset);

                    $state->setBlob($media->id, [
                        'upload_session_id' => $sessionId,
                        'start_offset' => $startOffset,
                        'end_offset' => $endOffset,
                    ]);
                    $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
                }
            } finally {
                fclose($stream);
            }

            $finish = $this->http->asForm()->post($url, [
                'upload_phase' => 'finish',
                'upload_session_id' => $sessionId,
                'description' => $text,
                'access_token' => $token,
            ]);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $finish);

            if ($finish->failed()) {
                throw new FacebookRequestFailed($finish);
            }

            if ($finish->json('success') !== true) {
                return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not confirm the video upload finished.');
            }
        } catch (FacebookRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success([$videoId]);
    }

    /**
     * Publish a Reel via the resumable /{page-id}/video_reels flow: start (returns
     * an rupload URL + video id) → stream the file to that URL → finish PUBLISHED
     * with the description. The video id is persisted after start so a retry
     * resumes rather than restarts.
     */
    private function publishReel(PublishContext $context, PostMedia $media, string $pageId, string $text, string $token): PublishResult
    {
        $state = new MediaUploadState($context->target->media_upload_state);
        $videoId = $state->remoteRef($media->id);
        $blob = $state->blob($media->id);
        $uploadUrl = is_string($blob['upload_url'] ?? null) ? $blob['upload_url'] : null;

        $disk = Storage::disk($media->disk);
        $totalSize = (int) $disk->size($media->path);
        $reelsUrl = $this->baseUrl().'/'.$pageId.'/video_reels';

        try {
            if ($videoId === null || $uploadUrl === null) {
                $start = $this->http->asForm()->post($reelsUrl, [
                    'upload_phase' => 'start',
                    'access_token' => $token,
                ]);
                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $start);

                if ($start->failed()) {
                    throw new FacebookRequestFailed($start);
                }

                $videoId = (string) $start->json('video_id');
                $uploadUrl = (string) $start->json('upload_url');
                if ($videoId === '' || $uploadUrl === '') {
                    return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not return a video upload session.');
                }
                $state->markUploaded($media->id, $videoId);
                $state->setBlob($media->id, ['upload_url' => $uploadUrl]);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            }

            $stream = $disk->readStream($media->path);
            try {
                $upload = $this->http
                    ->withHeaders([
                        'Authorization' => 'OAuth '.$token,
                        'offset' => '0',
                        'file_size' => (string) $totalSize,
                    ])
                    ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                    ->post($uploadUrl);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $upload);

            if ($upload->failed()) {
                throw new FacebookRequestFailed($upload);
            }

            $finish = $this->http->asForm()->post($reelsUrl, [
                'upload_phase' => 'finish',
                'video_id' => $videoId,
                'video_state' => 'PUBLISHED',
                'description' => $text,
                'access_token' => $token,
            ]);
            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $finish);

            if ($finish->failed()) {
                throw new FacebookRequestFailed($finish);
            }

            if ($finish->json('success') !== true) {
                return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not confirm the reel was published.');
            }
        } catch (FacebookRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success([$videoId]);
    }

    /**
     * Publish a Story: a photo Story uploads the image unpublished then creates the
     * story from that photo id; a video Story delegates to the resumable video_stories
     * flow. Neither carries a caption — Meta's Story endpoints take no text field.
     */
    private function publishStory(PublishContext $context, PostMedia $media, string $pageId, string $token): PublishResult
    {
        if ($media->isVideo()) {
            return $this->publishVideoStory($context, $media, $pageId, $token);
        }

        try {
            $bytes = (string) Storage::disk($media->disk)->get($media->path);
            $compressed = $this->imageCompressor->compressToFit($bytes, Platform::Facebook->maxMediaBytes(), $media->mime, Platform::Facebook->allowedMime());

            $upload = $this->http
                ->asMultipart()
                ->attach('source', $compressed->bytes, basename($media->path))
                ->post($this->baseUrl().'/'.$pageId.'/photos?published=false', [
                    'access_token' => $token,
                ]);
            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $upload);

            if ($upload->failed()) {
                throw new FacebookRequestFailed($upload);
            }

            $photoId = (string) $upload->json('id');

            $story = $this->http->asForm()->post($this->baseUrl().'/'.$pageId.'/photo_stories', [
                'photo_id' => $photoId,
                'access_token' => $token,
            ]);
            $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $story);

            if ($story->failed()) {
                throw new FacebookRequestFailed($story);
            }

            if ($story->json('success') !== true) {
                return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not confirm the story was created.');
            }

            $id = (string) ($story->json('post_id') ?? $story->json('id'));
        } catch (FacebookRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        if ($id === '') {
            return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not return a story id');
        }

        return PublishResult::success([$id]);
    }

    /**
     * Publish a video Story via /{page-id}/video_stories: start → stream to the
     * rupload URL → finish. No text field. Persists the video id for resume.
     */
    private function publishVideoStory(PublishContext $context, PostMedia $media, string $pageId, string $token): PublishResult
    {
        $state = new MediaUploadState($context->target->media_upload_state);
        $videoId = $state->remoteRef($media->id);
        $blob = $state->blob($media->id);
        $uploadUrl = is_string($blob['upload_url'] ?? null) ? $blob['upload_url'] : null;

        $disk = Storage::disk($media->disk);
        $totalSize = (int) $disk->size($media->path);
        $storiesUrl = $this->baseUrl().'/'.$pageId.'/video_stories';

        try {
            if ($videoId === null || $uploadUrl === null) {
                $start = $this->http->asForm()->post($storiesUrl, [
                    'upload_phase' => 'start',
                    'access_token' => $token,
                ]);
                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $start);

                if ($start->failed()) {
                    throw new FacebookRequestFailed($start);
                }

                $videoId = (string) $start->json('video_id');
                $uploadUrl = (string) $start->json('upload_url');
                if ($videoId === '' || $uploadUrl === '') {
                    return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not return a video upload session.');
                }
                $state->markUploaded($media->id, $videoId);
                $state->setBlob($media->id, ['upload_url' => $uploadUrl]);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            }

            $stream = $disk->readStream($media->path);
            try {
                $upload = $this->http
                    ->withHeaders([
                        'Authorization' => 'OAuth '.$token,
                        'offset' => '0',
                        'file_size' => (string) $totalSize,
                    ])
                    ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                    ->post($uploadUrl);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $upload);

            if ($upload->failed()) {
                throw new FacebookRequestFailed($upload);
            }

            $finish = $this->http->asForm()->post($storiesUrl, [
                'upload_phase' => 'finish',
                'video_id' => $videoId,
                'access_token' => $token,
            ]);
            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $finish);

            if ($finish->failed()) {
                throw new FacebookRequestFailed($finish);
            }

            if ($finish->json('success') !== true) {
                return PublishResult::failure(ErrorKind::ServerError, 'Facebook did not confirm the story upload finished.');
            }
        } catch (FacebookRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success([$videoId]);
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
