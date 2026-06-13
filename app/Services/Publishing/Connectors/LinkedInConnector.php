<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

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

    /**
     * Recent LinkedIn versioned-API month. LinkedIn sunsets versions roughly 12 months
     * after release, so this is the configurable default rather than a hardcoded constant.
     */
    public const string DEFAULT_VERSION = '202505';

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

        try {
            $images = $this->uploadImages($context->media, $author, $token);

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
