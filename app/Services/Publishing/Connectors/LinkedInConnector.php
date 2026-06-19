<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

class LinkedInConnector implements PublishConnector
{
    use MapsHttpErrors;

    private const string POSTS_URL = 'https://api.linkedin.com/rest/posts';

    private const string IMAGES_URL = 'https://api.linkedin.com/rest/images?action=initializeUpload';

    private const string VIDEOS_INIT_URL = 'https://api.linkedin.com/rest/videos?action=initializeUpload';

    private const string VIDEOS_FINALIZE_URL = 'https://api.linkedin.com/rest/videos?action=finalizeUpload';

    private const string VIDEOS_URL = 'https://api.linkedin.com/rest/videos';

    /**
     * Recent LinkedIn versioned-API month. LinkedIn sunsets versions roughly 12 months
     * after release, so this is the configurable default rather than a hardcoded constant.
     */
    public const string DEFAULT_VERSION = '202605';

    public function __construct(private readonly HttpFactory $http) {}

    private function apiVersion(): string
    {
        return (string) config('services.linkedin-openid.api_version', self::DEFAULT_VERSION);
    }

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'LinkedIn access token unavailable; reconnect the account.');
        }

        if (($context->target->remote_id ?? null) !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id]);
        }

        $author = 'urn:li:person:'.$context->account->remote_account_id;
        $text = $context->segments[0] ?? '';

        $videoMedia = array_values(array_filter($context->media, fn (PostMedia $m): bool => $m->isVideo()));
        $videoUrn = null;

        if ($videoMedia !== []) {
            $ready = $this->ensureVideoReady($context, $videoMedia[0], $author, $token);
            if (! $ready->isSuccessful()) {
                return $ready;
            }
            $videoUrn = (string) $ready->remoteIds[0];
        }

        try {
            $body = [
                'author' => $author,
                'commentary' => $text,
                'visibility' => 'PUBLIC',
                'lifecycleState' => 'PUBLISHED',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
            ];

            if ($videoUrn !== null) {
                $body['content'] = ['media' => ['id' => $videoUrn, 'title' => '']];
            } else {
                $images = $this->uploadImages($context->media, $author, $token);

                if (count($images) === 1) {
                    $body['content'] = ['media' => ['id' => $images[0]['urn'], 'altText' => $images[0]['altText']]];
                } elseif (count($images) > 1) {
                    $body['content'] = [
                        'multiImage' => [
                            'images' => array_map(
                                fn (array $image): array => ['id' => $image['urn'], 'altText' => $image['altText']],
                                $images,
                            ),
                        ],
                    ];
                }
            }

            $response = $this->http
                ->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->post(self::POSTS_URL, $body);

            if ($response->failed()) {
                return $this->mapFailure($response);
            }

            $urn = $response->header('x-restli-id') ?: (string) $response->json('id');
        } catch (LinkedInRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }

        if ($urn === '') {
            return PublishResult::failure(ErrorKind::ServerError, 'LinkedIn did not return a post id');
        }

        return PublishResult::success([$urn]);
    }

    /**
     * Register + upload each image, returning the created asset URNs (with alt text) in order.
     *
     * @param  list<PostMedia>  $media
     * @return list<array{urn: string, altText: string}>
     */
    private function uploadImages(array $media, string $author, string $token): array
    {
        $media = array_slice($media, 0, Platform::LinkedIn->maxMedia());
        $images = [];

        foreach ($media as $item) {
            $register = $this->http
                ->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->post(self::IMAGES_URL, ['initializeUploadRequest' => ['owner' => $author]]);

            if ($register->failed()) {
                throw new LinkedInRequestFailed($register);
            }

            $uploadUrl = (string) $register->json('value.uploadUrl');
            $urn = (string) $register->json('value.image');

            $bytes = (string) Storage::disk($item->disk)->get($item->path);

            $upload = $this->http
                ->withToken($token)
                ->withBody($bytes, $item->mime)
                ->put($uploadUrl);

            if ($upload->failed()) {
                throw new LinkedInRequestFailed($upload);
            }

            $images[] = ['urn' => $urn, 'altText' => (string) ($item->alt_text ?? '')];
        }

        return $images;
    }

    private function ensureVideoReady(PublishContext $context, PostMedia $media, string $author, string $token): PublishResult
    {
        $state = new MediaUploadState($context->target->media_upload_state);
        $urn = $state->remoteRef($media->id);

        try {
            if ($urn === null) {
                $urn = $this->uploadVideo($media, $author, $token);
                $state->markUploaded($media->id, $urn);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            }

            $status = $this->http->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->get(self::VIDEOS_URL.'/'.rawurlencode($urn));

            if ($status->failed()) {
                $kind = $this->classifyStatus($status->status());
                if (in_array($kind, [ErrorKind::ServerError, ErrorKind::RateLimited], true)) {
                    // A transient failure to CHECK status is not a publish failure — treat it as
                    // "still processing, try again" so it uses the media-poll budget, not the
                    // 5-attempt publish-failure budget.
                    return PublishResult::failure(
                        ErrorKind::MediaProcessing,
                        'Could not check video processing status; will retry.',
                        retryAfter: $this->retryAfter($status) ?? 6,
                    );
                }

                // Non-transient (auth/validation/etc.) — surface as a real failure.
                return $this->mapFailure($status);
            }

            $videoStatus = (string) $status->json('status', 'AVAILABLE');

            if ($videoStatus === 'PROCESSING_FAILED') {
                return PublishResult::failure(ErrorKind::ServerError, 'LinkedIn failed to process the video.');
            }

            if ($videoStatus !== 'AVAILABLE') {
                return PublishResult::failure(ErrorKind::MediaProcessing, 'Video is still processing on LinkedIn.', retryAfter: 10);
            }

            return PublishResult::success([$urn]);
        } catch (LinkedInRequestFailed $e) {
            return $this->mapFailure($e->response);
        }
    }

    private function uploadVideo(PostMedia $media, string $author, string $token): string
    {
        $disk = Storage::disk($media->disk);
        $total = (int) $disk->size($media->path);
        $headers = ['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'];

        $init = $this->http->withToken($token)->withHeaders($headers)->acceptJson()
            ->post(self::VIDEOS_INIT_URL, [
                'initializeUploadRequest' => ['owner' => $author, 'fileSizeBytes' => $total],
            ]);
        if ($init->failed()) {
            throw new LinkedInRequestFailed($init);
        }

        $urn = (string) $init->json('value.video');
        $uploadToken = (string) $init->json('value.uploadToken', '');
        /** @var list<array{uploadUrl: string, firstByte: int, lastByte: int}> $instructions */
        $instructions = (array) $init->json('value.uploadInstructions', []);

        // Stream each part's byte range from disk; never hold the whole file.
        $etags = [];
        $stream = $disk->readStream($media->path);
        try {
            foreach ($instructions as $instruction) {
                $first = (int) $instruction['firstByte'];
                $length = (int) $instruction['lastByte'] - $first + 1;
                fseek($stream, $first);
                $part = (string) stream_get_contents($stream, $length);
                $put = $this->http->withToken($token)->withBody($part, 'application/octet-stream')->put($instruction['uploadUrl']);
                if ($put->failed()) {
                    throw new LinkedInRequestFailed($put);
                }
                $etags[] = (string) $put->header('etag');
            }
        } finally {
            fclose($stream);
        }

        $finalize = $this->http->withToken($token)->withHeaders($headers)->acceptJson()
            ->post(self::VIDEOS_FINALIZE_URL, [
                'finalizeUploadRequest' => ['video' => $urn, 'uploadToken' => $uploadToken, 'uploadedPartIds' => $etags],
            ]);
        if ($finalize->failed()) {
            throw new LinkedInRequestFailed($finalize);
        }

        return $urn;
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $urn = $target->remote_id;

        if ($token === '' || $urn === null) {
            return;
        }

        $this->http
            ->withToken($token)
            ->withHeaders(['LinkedIn-Version' => $this->apiVersion()])
            ->delete(self::POSTS_URL.'/'.rawurlencode($urn));
    }

    private function mapFailure(Response $response): PublishResult
    {
        $kind = $this->classifyStatus($response->status());
        $message = (string) ($response->json('message') ?? 'LinkedIn request failed');

        return PublishResult::failure($kind, $message, $response->status(), $this->excerpt($response), $this->retryAfter($response));
    }
}

/**
 * Internal signal so a failed media register/upload short-circuits to the shared
 * HTTP-error mapping. Not part of the public connector surface.
 *
 * @internal
 */
final class LinkedInRequestFailed extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('LinkedIn request failed.');
    }
}
