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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LinkedInConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

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

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ImageCompressor $imageCompressor,
    ) {}

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
        $text = implode("\n", array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        )));

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
                $images = $this->uploadImages($context->media, $author, $token, $context->account);

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
                } else {
                    $article = $this->articleFromText($text, $author, $token, $context->account);

                    if ($article !== null) {
                        $body['content'] = ['article' => $article];
                    }
                }
            }

            $response = $this->http
                ->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->post(self::POSTS_URL, $body);

            $this->meter(UsageCategory::Publish, UsageOperation::POST, $context->account, $response);

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
     * LinkedIn's Posts API does not scrape links automatically. When a text-only
     * post includes a URL, send an explicit article payload so it renders like a
     * manually-created link post.
     *
     * @return array{source: string, title: string, description?: string, thumbnail?: string}|null
     */
    private function articleFromText(string $text, string $author, string $token, ConnectedAccount $account): ?array
    {
        $url = $this->firstUrl($text);

        if ($url === null) {
            return null;
        }

        try {
            $response = $this->http
                ->timeout(5)
                ->connectTimeout(3)
                ->accept('text/html,application/xhtml+xml')
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();
            $source = (string) ($response->effectiveUri() ?? $url);
            $title = $this->metaContent($html, ['og:title', 'twitter:title']) ?? $this->titleTag($html);

            if ($title === null) {
                $title = parse_url($source, PHP_URL_HOST) ?: $source;
            }

            $article = [
                'source' => $source,
                'title' => Str::limit($title, 200, ''),
            ];

            $description = $this->metaContent($html, ['og:description', 'twitter:description', 'description']);
            if ($description !== null) {
                $article['description'] = Str::limit($description, 256, '');
            }

            $imageUrl = $this->absoluteUrl($this->metaContent($html, ['og:image', 'twitter:image']), $source);
            if ($imageUrl !== null) {
                $thumbnail = $this->uploadArticleThumbnail($imageUrl, $author, $token, $account);

                if ($thumbnail !== null) {
                    $article['thumbnail'] = $thumbnail;
                }
            }

            return $article;
        } catch (Throwable) {
            return null;
        }
    }

    private function firstUrl(string $text): ?string
    {
        if (! preg_match('~https?://[^\s<>"\']+~i', $text, $matches)) {
            return null;
        }

        return rtrim($matches[0], '.,!?)]}');
    }

    /**
     * @param  list<string>  $names
     */
    private function metaContent(string $html, array $names): ?string
    {
        foreach ($names as $name) {
            $quotedName = preg_quote($name, '~');
            $patterns = [
                '~<meta\b(?=[^>]*(?:property|name)=["\']'.$quotedName.'["\'])(?=[^>]*content=["\']([^"\']+)["\'])[^>]*>~i',
                '~<meta\b(?=[^>]*content=["\']([^"\']+)["\'])(?=[^>]*(?:property|name)=["\']'.$quotedName.'["\'])[^>]*>~i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
        }

        return null;
    }

    private function titleTag(string $html): ?string
    {
        if (! preg_match('~<title[^>]*>(.*?)</title>~is', $html, $matches)) {
            return null;
        }

        return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function absoluteUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        if (Str::startsWith($url, '//')) {
            return $scheme.':'.$url;
        }

        if (Str::startsWith($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = Str::beforeLast($path, '/');

        return $scheme.'://'.$host.$directory.'/'.$url;
    }

    private function uploadArticleThumbnail(string $url, string $author, string $token, ConnectedAccount $account): ?string
    {
        try {
            $image = $this->http->timeout(10)->connectTimeout(3)->get($url);

            if ($image->failed()) {
                return null;
            }

            $register = $this->http
                ->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->post(self::IMAGES_URL, ['initializeUploadRequest' => ['owner' => $author]]);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $register);

            if ($register->failed()) {
                return null;
            }

            $uploadUrl = (string) $register->json('value.uploadUrl');
            $urn = (string) $register->json('value.image');

            $upload = $this->http
                ->withToken($token)
                ->withBody($image->body(), $image->header('Content-Type') ?: 'image/jpeg')
                ->put($uploadUrl);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $upload);

            return $upload->failed() || $urn === '' ? null : $urn;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Register + upload each image, returning the created asset URNs (with alt text) in order.
     *
     * @param  list<PostMedia>  $media
     * @return list<array{urn: string, altText: string}>
     */
    private function uploadImages(array $media, string $author, string $token, ConnectedAccount $account): array
    {
        $media = array_slice($media, 0, Platform::LinkedIn->maxMedia());
        $images = [];

        foreach ($media as $item) {
            $register = $this->http
                ->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->post(self::IMAGES_URL, ['initializeUploadRequest' => ['owner' => $author]]);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $register);

            if ($register->failed()) {
                throw new LinkedInRequestFailed($register);
            }

            $uploadUrl = (string) $register->json('value.uploadUrl');
            $urn = (string) $register->json('value.image');

            $bytes = (string) Storage::disk($item->disk)->get($item->path);
            $compressed = $this->imageCompressor->compressToFit($bytes, Platform::LinkedIn->maxMediaBytes(), $item->mime, Platform::LinkedIn->allowedMime());

            $upload = $this->http
                ->withToken($token)
                ->withBody($compressed->bytes, $compressed->mime)
                ->put($uploadUrl);

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $upload);

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
                $urn = $this->uploadVideo($media, $author, $token, $context->account);
                $state->markUploaded($media->id, $urn);
                $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
            }

            $status = $this->http->withToken($token)
                ->withHeaders(['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->acceptJson()
                ->get(self::VIDEOS_URL.'/'.rawurlencode($urn));

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $status);

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

    private function uploadVideo(PostMedia $media, string $author, string $token, ConnectedAccount $account): string
    {
        $disk = Storage::disk($media->disk);
        $total = (int) $disk->size($media->path);
        $headers = ['LinkedIn-Version' => $this->apiVersion(), 'X-Restli-Protocol-Version' => '2.0.0'];

        $init = $this->http->withToken($token)->withHeaders($headers)->acceptJson()
            ->post(self::VIDEOS_INIT_URL, [
                'initializeUploadRequest' => ['owner' => $author, 'fileSizeBytes' => $total],
            ]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $init);

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

                $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $put);

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

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $account, $finalize);

        if ($finalize->failed()) {
            throw new LinkedInRequestFailed($finalize);
        }

        return $urn;
    }

    public function delete(PostTarget $target, array $credentials): void
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $urn = $target->remote_id;

        if ($urn === null) {
            return;
        }

        if ($token === '') {
            throw new RuntimeException('LinkedIn access token unavailable; reconnect the account.');
        }

        $response = $this->http
            ->withToken($token)
            ->withHeaders(['LinkedIn-Version' => $this->apiVersion()])
            ->delete(self::POSTS_URL.'/'.rawurlencode($urn));

        // A 404 means the post is already gone — throwUnlessDeleteAccepted treats it as done.
        $this->meter(UsageCategory::Publish, UsageOperation::DELETE, $target->account, $response, succeeded: $response->successful() || $response->status() === 404);

        $this->throwUnlessDeleteAccepted($response);
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
final class LinkedInRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('LinkedIn request failed.');
    }
}
