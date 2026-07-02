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
use App\Services\Atproto\DPoP;
use App\Services\Media\ConvertedVideo;
use App\Services\Media\GifToMp4ConversionFailed;
use App\Services\Media\GifToMp4Converter;
use App\Services\Media\GifToMp4ConverterUnavailable;
use App\Services\Media\GifToMp4OutputTooLarge;
use App\Services\Media\ImageCompressor;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class BlueskyPublishConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string DEFAULT_PDS = 'https://bsky.social';

    private const string VIDEO_SERVICE = 'https://video.bsky.app';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ImageCompressor $imageCompressor,
        private readonly DPoP $dpop,
        private readonly GifToMp4Converter $gifToMp4Converter,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        $session = (array) ($context->credentials['session'] ?? []);
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);
        $jwt = (string) ($session['accessJwt'] ?? '');
        $did = $context->account->remote_account_id;

        $remoteIds = $context->target->remote_ids ?? [];
        $rootUri = $remoteIds[0] ?? null;
        $rootCid = null;
        $parentUri = $rootUri;
        $parentCid = null;

        try {
            // Video takes precedence over images on the root post only.
            $videoMedia = array_values(array_filter($context->media, fn (PostMedia $m): bool => $m->isVideo()));
            $gifMedia = array_values(array_filter(
                $context->media,
                fn (PostMedia $m): bool => ! $m->isVideo() && $m->mime === 'image/gif',
            ));

            if ($rootUri === null && $videoMedia !== []) {
                $ready = $this->ensureVideoReady($context, $videoMedia[0], $pds, $jwt, $did, $session);
                if (! $ready->isSuccessful()) {
                    return $ready;
                }
                $embed = $this->videoEmbed($context, $videoMedia[0]);
            } elseif ($rootUri === null && $gifMedia !== []) {
                $ready = $this->ensureGifVideoReady($context, $gifMedia, $pds, $jwt, $did, $session);
                if (! $ready->isSuccessful()) {
                    return $ready;
                }
                $embed = $this->videoEmbed($context, $gifMedia[0], 'gif');
            } else {
                // Media rides on the root post only; uploaded once, then embedded below.
                $embed = $rootUri === null ? $this->uploadImages($context->media, $pds, $jwt, $session, $context->account) : null;
            }

            // Resume: remote_ids stores only AT-URIs, so recover the root and parent CIDs
            // (needed for threading) from the already-posted records before continuing.
            if ($rootUri !== null) {
                $rootCid = $this->recordCid($pds, $jwt, $did, $rootUri, $session);
                $parentUri = (string) end($remoteIds);
                $parentCid = $this->recordCid($pds, $jwt, $did, $parentUri, $session);
            }

            foreach ($context->segments as $index => $text) {
                if (isset($remoteIds[$index])) {
                    continue;
                }

                $record = [
                    '$type' => 'app.bsky.feed.post',
                    'text' => $text,
                    'facets' => $this->richTextFacets($text),
                    'createdAt' => Date::now()->toIso8601String(),
                    'langs' => ['en'],
                ];

                if ($index === 0 && $embed !== null) {
                    $record['embed'] = $embed;
                }

                if ($rootUri !== null && $rootCid !== null && $parentUri !== null && $parentCid !== null) {
                    $record['reply'] = [
                        'root' => ['uri' => $rootUri, 'cid' => $rootCid],
                        'parent' => ['uri' => $parentUri, 'cid' => $parentCid],
                    ];
                }

                $response = $this->postJsonAuthorized($pds.'/xrpc/com.atproto.repo.createRecord', $jwt, $session, [
                    'repo' => $did,
                    'collection' => 'app.bsky.feed.post',
                    'record' => $record,
                ]);

                $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $response);

                if ($response->failed()) {
                    return $this->mapFailure($response);
                }

                $uri = (string) $response->json('uri');
                $cid = (string) $response->json('cid');
                $remoteIds[$index] = $uri;

                // Persist this segment's uri BEFORE sending the next one so a mid-thread
                // death resumes (rather than re-posts) the already-published segments (spec §4.3).
                $context->target->forceFill([
                    'remote_id' => $remoteIds[0],
                    'remote_ids' => array_values($remoteIds),
                ])->save();

                if ($rootUri === null) {
                    $rootUri = $uri;
                    $rootCid = $cid;
                }

                $parentUri = $uri;
                $parentCid = $cid;
            }
        } catch (BlueskyRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (BlueskyValidationFailed $e) {
            return PublishResult::failure(ErrorKind::Validation, $e->getMessage());
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        return PublishResult::success(array_values($remoteIds));
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $session = (array) ($credentials['session'] ?? []);
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);
        $jwt = (string) ($session['accessJwt'] ?? '');
        $did = $target->account->remote_account_id;

        foreach ($target->remote_ids ?? array_filter([$target->remote_id]) as $uri) {
            $rkey = (string) (explode('/', (string) $uri)[4] ?? '');

            $response = $this->postJsonAuthorized($pds.'/xrpc/com.atproto.repo.deleteRecord', $jwt, $session, [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'rkey' => $rkey,
            ]);

            // A 404 means the post is already gone — throwUnlessDeleteAccepted treats it as done.
            $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $response->successful() || $response->status() === 404);

            $this->throwUnlessDeleteAccepted($response);
        }
    }

    /**
     * Fetch the CID of an already-posted record so a resumed thread can reference it.
     * The rkey is the 5th `/`-split segment of the at-uri (same extraction as delete()).
     *
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     */
    private function recordCid(string $pds, string $jwt, string $did, string $uri, array $session): string
    {
        $rkey = (string) (explode('/', $uri)[4] ?? '');

        $response = $this->getAuthorized($pds.'/xrpc/com.atproto.repo.getRecord', $jwt, $session, [
            'repo' => $did,
            'collection' => 'app.bsky.feed.post',
            'rkey' => $rkey,
        ]);

        if ($response->failed()) {
            throw new BlueskyRequestFailed($response);
        }

        return (string) $response->json('cid');
    }

    /**
     * Ensure the video job is running or completed. On first call, mints a service-auth token
     * and uploads the video, persisting the jobId. On subsequent calls, polls getJobStatus.
     * Returns a successful PublishResult only when the job has completed.
     *
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     */
    private function ensureVideoReady(PublishContext $context, PostMedia $media, string $pds, string $jwt, string $did, array $session): PublishResult
    {
        return $this->ensureVideoUploadReady(
            $context,
            $media,
            fn (): string => $this->uploadVideo($media, $pds, $jwt, $did, $session, $context->account),
        );
    }

    /**
     * @param  list<PostMedia>  $media
     * @param  array<string, mixed>  $session
     */
    private function ensureGifVideoReady(PublishContext $context, array $media, string $pds, string $jwt, string $did, array $session): PublishResult
    {
        if (count($context->media) > 1 || count($media) > 1) {
            return PublishResult::failure(
                ErrorKind::Validation,
                'Bluesky supports one animated GIF per post, and it cannot be mixed with other media.',
            );
        }

        $item = $media[0];

        try {
            return $this->ensureVideoUploadReady(
                $context,
                $item,
                function () use ($item, $pds, $jwt, $did, $session, $context): string {
                    $converted = $this->gifToMp4Converter->convert($item, Platform::Bluesky->maxVideoBytes());

                    return $this->uploadConvertedVideo($converted, $pds, $jwt, $did, $session, $context->account);
                },
                'GIF',
            );
        } catch (GifToMp4ConverterUnavailable $e) {
            // ffmpeg is missing on the server — a config problem, not a transient one.
            return PublishResult::failure(ErrorKind::Unsupported, $e->getMessage());
        } catch (GifToMp4OutputTooLarge $e) {
            return PublishResult::failure(ErrorKind::Validation, $e->getMessage());
        } catch (GifToMp4ConversionFailed $e) {
            return PublishResult::failure(ErrorKind::ServerError, $e->getMessage());
        }
    }

    /**
     * @param  Closure(): string  $upload
     */
    private function ensureVideoUploadReady(PublishContext $context, PostMedia $media, Closure $upload, string $label = 'video'): PublishResult
    {
        $state = new MediaUploadState($context->target->media_upload_state);
        $jobId = $state->remoteRef($media->id);

        try {
            if ($jobId === null) {
                $jobId = $upload();
                $state->markUploaded($media->id, $jobId);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            }

            $status = $this->http->acceptJson()
                ->get(self::VIDEO_SERVICE.'/xrpc/app.bsky.video.getJobStatus', ['jobId' => $jobId]);

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

            $jobState = (string) $status->json('jobStatus.state', '');

            if ($jobState === 'JOB_STATE_FAILED') {
                return PublishResult::failure(ErrorKind::ServerError, (string) $status->json('jobStatus.error', "Bluesky failed to process the {$label}."));
            }

            if ($jobState !== 'JOB_STATE_COMPLETED') {
                return PublishResult::failure(ErrorKind::MediaProcessing, ucfirst($label).' is still processing on Bluesky.', retryAfter: 6);
            }

            // Stash the completed blob in media_upload_state so videoEmbed() can read it.
            $state->setBlob($media->id, (array) $status->json('jobStatus.blob'));
            $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();

            return PublishResult::success([$jobId]);
        } catch (BlueskyRequestFailed $e) {
            return $this->mapFailure($e->response);
        }
    }

    /**
     * Mint a service-auth token scoped to the user's PDS for blob upload, then push raw
     * bytes to the video service (which cannot be PDS-proxied).
     *
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     */
    private function uploadVideo(PostMedia $media, string $pds, string $jwt, string $did, array $session, ConnectedAccount $account): string
    {
        return $this->uploadVideoFile($media->disk, $media->path, $pds, $jwt, $did, $session, $account);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function uploadConvertedVideo(ConvertedVideo $video, string $pds, string $jwt, string $did, array $session, ConnectedAccount $account): string
    {
        return $this->uploadVideoFile($video->disk, $video->path, $pds, $jwt, $did, $session, $account, 'animation.mp4');
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function uploadVideoFile(string $diskName, string $path, string $pds, string $jwt, string $did, array $session, ConnectedAccount $account, string $name = 'video.mp4'): string
    {
        $pdsHost = (string) parse_url($pds, PHP_URL_HOST);

        $auth = $this->getAuthorized($pds.'/xrpc/com.atproto.server.getServiceAuth', $jwt, $session, [
            'aud' => 'did:web:'.$pdsHost,
            'lxm' => 'com.atproto.repo.uploadBlob',
            'exp' => time() + 1800,
        ]);

        if ($auth->failed()) {
            throw new BlueskyRequestFailed($auth);
        }

        $serviceToken = (string) $auth->json('token');

        // Stream the file as the request body (wrap the disk resource as a PSR-7 stream)
        // so the whole video is never resident in memory.
        $body = Utils::streamFor(Storage::disk($diskName)->readStream($path));

        $upload = $this->http->withToken($serviceToken)->withBody($body, 'video/mp4')
            ->post(self::VIDEO_SERVICE.'/xrpc/app.bsky.video.uploadVideo?did='.rawurlencode($did).'&name='.rawurlencode($name));

        // A 409 already_exists still carries a usable jobId, so it counts as a successful upload.
        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $upload, succeeded: $upload->successful() || $upload->json('error') === 'already_exists');

        // Re-uploading identical bytes returns 409 already_exists but still carries the jobId.
        if ($upload->failed() && $upload->json('error') !== 'already_exists') {
            throw new BlueskyRequestFailed($upload);
        }

        return (string) $upload->json('jobId');
    }

    /**
     * Build an `app.bsky.embed.video` embed using the blob stashed in media_upload_state.
     *
     * @return array{'$type': string, video: array<string, mixed>, alt?: string, presentation?: string}
     */
    private function videoEmbed(PublishContext $context, PostMedia $media, ?string $presentation = null): array
    {
        $blob = (new MediaUploadState($context->target->media_upload_state))->blob($media->id);

        $embed = ['$type' => 'app.bsky.embed.video', 'video' => $blob];

        if ($presentation !== null) {
            $embed['presentation'] = $presentation;
        }

        if (($media->alt_text ?? '') !== '') {
            $embed['alt'] = (string) $media->alt_text;
        }

        return $embed;
    }

    /**
     * Upload each media item as a blob and build an `app.bsky.embed.images` embed.
     *
     * @param  list<PostMedia>  $media
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     * @return array{'$type': string, images: list<array{alt: string, image: array<string, mixed>}>}|null
     */
    private function uploadImages(array $media, string $pds, string $jwt, array $session, ConnectedAccount $account): ?array
    {
        $media = array_slice($media, 0, Platform::Bluesky->maxMedia());

        if ($media === []) {
            return null;
        }

        $images = [];

        foreach ($media as $item) {
            $bytes = (string) Storage::disk($item->disk)->get($item->path);
            $compressed = $this->imageCompressor->compressToFit($bytes, Platform::Bluesky->maxMediaBytes(), $item->mime, Platform::Bluesky->allowedMime());

            if (strlen($compressed->bytes) > Platform::Bluesky->maxMediaBytes()) {
                throw new BlueskyValidationFailed('Bluesky images must be 2 MB or smaller.');
            }

            $response = $this->postBodyAuthorized($pds.'/xrpc/com.atproto.repo.uploadBlob', $jwt, $session, $compressed->bytes, $compressed->mime);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $response);

            if ($response->failed()) {
                throw new BlueskyRequestFailed($response);
            }

            $images[] = [
                'alt' => (string) ($item->alt_text ?? ''),
                'image' => (array) $response->json('blob'),
            ];
        }

        return ['$type' => 'app.bsky.embed.images', 'images' => $images];
    }

    /**
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     * @param  array<string, mixed>  $payload
     */
    private function postJsonAuthorized(string $url, string $jwt, array $session, array $payload): Response
    {
        $response = $this->authorized('POST', $url, $jwt, $session)->acceptJson()->post($url, $payload);
        $nonce = $this->responseNonce($response);

        return $response->failed() && $nonce !== null
            ? $this->authorized('POST', $url, $jwt, $session, $nonce)->acceptJson()->post($url, $payload)
            : $response;
    }

    /**
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     * @param  array<string, mixed>  $query
     */
    private function getAuthorized(string $url, string $jwt, array $session, array $query): Response
    {
        $response = $this->authorized('GET', $url, $jwt, $session)->acceptJson()->get($url, $query);
        $nonce = $this->responseNonce($response);

        return $response->failed() && $nonce !== null
            ? $this->authorized('GET', $url, $jwt, $session, $nonce)->acceptJson()->get($url, $query)
            : $response;
    }

    /**
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     */
    private function postBodyAuthorized(string $url, string $jwt, array $session, string $body, string $mime): Response
    {
        $response = $this->authorized('POST', $url, $jwt, $session)->withBody($body, $mime)->post($url);
        $nonce = $this->responseNonce($response);

        return $response->failed() && $nonce !== null
            ? $this->authorized('POST', $url, $jwt, $session, $nonce)->withBody($body, $mime)->post($url)
            : $response;
    }

    private function responseNonce(Response $response): ?string
    {
        $nonce = $response->header('DPoP-Nonce');

        return $nonce !== '' ? $nonce : null;
    }

    /**
     * @param  array{dpop_private_jwk?: array{kty: string, crv: string, x: string, y: string, d: string}, dpop_nonce?: string|null}  $session
     */
    private function authorized(string $method, string $url, string $jwt, array $session, ?string $nonce = null): PendingRequest
    {
        $key = $session['dpop_private_jwk'] ?? null;

        if (is_array($key)) {
            return $this->http->withHeaders([
                'Authorization' => 'DPoP '.$jwt,
                'DPoP' => $this->dpop->proof($method, $url, $key, $jwt, $nonce ?? $session['dpop_nonce'] ?? null),
            ]);
        }

        return $this->http->withToken($jwt);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function richTextFacets(string $text): array
    {
        $facets = $this->linkFacets($text);

        if (preg_match_all('/(?<![A-Za-z0-9._-])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z][A-Za-z0-9.-]*)(?![A-Za-z0-9._-])/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $facets;
        }

        foreach ($matches[0] as $index => [$mention, $offset]) {
            $byteStart = $offset;
            $byteEnd = $offset + strlen($mention);

            if ($this->overlapsFacet($facets, $byteStart, $byteEnd)) {
                continue;
            }

            $did = $this->resolveHandle((string) $matches[1][$index][0]);
            if ($did === null) {
                continue;
            }

            $facets[] = [
                'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]],
            ];
        }

        usort($facets, static fn (array $left, array $right): int => $left['index']['byteStart'] <=> $right['index']['byteStart']);

        return $facets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkFacets(string $text): array
    {
        $facets = [];

        if (preg_match_all('#(?<![@A-Za-z0-9._-])((?:https?://)?(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?:/[^\s<]*)?)(?![A-Za-z0-9_-])#i', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $facets;
        }

        foreach ($matches[1] as [$url, $offset]) {
            $url = rtrim($url, '.,!?;:');

            $facets[] = [
                'index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url]],
            ];
        }

        return $facets;
    }

    /**
     * @param  list<array<string, mixed>>  $facets
     */
    private function overlapsFacet(array $facets, int $byteStart, int $byteEnd): bool
    {
        foreach ($facets as $facet) {
            $index = (array) ($facet['index'] ?? []);
            $facetStart = (int) ($index['byteStart'] ?? 0);
            $facetEnd = (int) ($index['byteEnd'] ?? 0);

            if ($byteStart < $facetEnd && $byteEnd > $facetStart) {
                return true;
            }
        }

        return false;
    }

    private function resolveHandle(string $handle): ?string
    {
        $response = $this->http
            ->acceptJson()
            ->get(self::DEFAULT_PDS.'/xrpc/com.atproto.identity.resolveHandle', ['handle' => $handle]);

        if ($response->failed()) {
            return null;
        }

        $did = (string) $response->json('did', '');

        return $did !== '' ? $did : null;
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('message') ?? $response->json('error') ?? 'Bluesky request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}

/**
 * Internal signal so a failed blob upload short-circuits to the shared HTTP-error
 * mapping without aborting the whole job. Not part of the public connector surface.
 *
 * @internal
 */
final class BlueskyRequestFailed extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Bluesky request failed.');
    }
}

/**
 * Internal signal for deterministic media validation before a request reaches Bluesky.
 *
 * @internal
 */
final class BlueskyValidationFailed extends \RuntimeException {}
