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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class BlueskyPublishConnector implements PublishConnector
{
    use MapsHttpErrors;

    private const string DEFAULT_PDS = 'https://bsky.social';

    public function __construct(private readonly HttpFactory $http) {}

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
            // Media rides on the root post only; uploaded once, then embedded below.
            $embed = $rootUri === null ? $this->uploadImages($context->media, $pds, $jwt) : null;

            // Resume: remote_ids stores only AT-URIs, so recover the root and parent CIDs
            // (needed for threading) from the already-posted records before continuing.
            if ($rootUri !== null) {
                $rootCid = $this->recordCid($pds, $jwt, $did, $rootUri);
                $parentUri = (string) end($remoteIds);
                $parentCid = $this->recordCid($pds, $jwt, $did, $parentUri);
            }

            foreach ($context->segments as $index => $text) {
                if (isset($remoteIds[$index])) {
                    continue;
                }

                $record = [
                    '$type' => 'app.bsky.feed.post',
                    'text' => $text,
                    'facets' => $this->linkFacets($text),
                    'createdAt' => Date::now()->toIso8601String(),
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

                $response = $this->http
                    ->withToken($jwt)
                    ->acceptJson()
                    ->post($pds.'/xrpc/com.atproto.repo.createRecord', [
                        'repo' => $did,
                        'collection' => 'app.bsky.feed.post',
                        'record' => $record,
                    ]);

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

            $this->http->withToken($jwt)->post($pds.'/xrpc/com.atproto.repo.deleteRecord', [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'rkey' => $rkey,
            ]);
        }
    }

    /**
     * Fetch the CID of an already-posted record so a resumed thread can reference it.
     * The rkey is the 5th `/`-split segment of the at-uri (same extraction as delete()).
     */
    private function recordCid(string $pds, string $jwt, string $did, string $uri): string
    {
        $rkey = (string) (explode('/', $uri)[4] ?? '');

        $response = $this->http
            ->withToken($jwt)
            ->acceptJson()
            ->get($pds.'/xrpc/com.atproto.repo.getRecord', [
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
     * Upload each media item as a blob and build an `app.bsky.embed.images` embed.
     *
     * @param  list<PostMedia>  $media
     * @return array{'$type': string, images: list<array{alt: string, image: array<string, mixed>}>}|null
     */
    private function uploadImages(array $media, string $pds, string $jwt): ?array
    {
        $media = array_slice($media, 0, Platform::Bluesky->maxMedia());

        if ($media === []) {
            return null;
        }

        $images = [];

        foreach ($media as $item) {
            $bytes = (string) Storage::disk($item->disk)->get($item->path);

            $response = $this->http
                ->withToken($jwt)
                ->withBody($bytes, $item->mime)
                ->post($pds.'/xrpc/com.atproto.repo.uploadBlob');

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
     * @return list<array<string, mixed>>
     */
    private function linkFacets(string $text): array
    {
        $facets = [];

        if (preg_match_all('#https?://\S+#', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $facets;
        }

        foreach ($matches[0] as [$url, $offset]) {
            $facets[] = [
                'index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
            ];
        }

        return $facets;
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
