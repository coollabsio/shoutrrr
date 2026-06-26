<?php

declare(strict_types=1);

namespace App\Services\Engagement\Connectors;

use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\EngagementConnector;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class BlueskyEngagementConnector implements EngagementConnector
{
    private const string APPVIEW = 'https://public.api.bsky.app';

    private const string DEFAULT_PDS = 'https://bsky.social';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        $rootUri = $target->remote_ids[0] ?? $target->remote_id;

        if ($rootUri === null) {
            return ReplyFetchResult::failed('Target has no remote id.');
        }

        try {
            $response = $this->http->acceptJson()->get(self::APPVIEW.'/xrpc/app.bsky.feed.getPostThread', [
                'uri' => $rootUri,
                'depth' => 10,
                'parentHeight' => 0,
            ]);
        } catch (ConnectionException $e) {
            return ReplyFetchResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $response->status() === 429
                ? ReplyFetchResult::rateLimited($this->excerpt($response))
                : ReplyFetchResult::failed($this->excerpt($response));
        }

        $replies = [];
        $this->flatten(
            array_values((array) $response->json('thread.replies', [])),
            $account->remote_account_id,
            $rootUri,
            $since,
            $replies,
        );

        return ReplyFetchResult::ok($replies);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<FetchedReply>  $out
     */
    private function flatten(array $nodes, string $ownerDid, string $parentUri, ?CarbonImmutable $since, array &$out): void
    {
        foreach ($nodes as $node) {
            $post = (array) ($node['post'] ?? []);
            $author = (array) ($post['author'] ?? []);
            $record = (array) ($post['record'] ?? []);
            $uri = (string) ($post['uri'] ?? '');

            $isOwner = ($author['did'] ?? null) === $ownerDid;
            $createdAt = isset($record['createdAt']) ? CarbonImmutable::parse((string) $record['createdAt']) : Date::now();
            $afterSince = $since === null || $createdAt->greaterThan($since);

            if ($uri !== '' && ! $isOwner && $afterSince) {
                $reply = $record['reply'] ?? null;
                $parentRemoteId = is_array($reply) ? (string) ($reply['parent']['uri'] ?? $parentUri) : $parentUri;

                $out[] = new FetchedReply(
                    remoteReplyId: $uri,
                    remoteCid: isset($post['cid']) ? (string) $post['cid'] : null,
                    parentRemoteId: $parentRemoteId,
                    authorHandle: (string) ($author['handle'] ?? ''),
                    authorName: isset($author['displayName']) ? (string) $author['displayName'] : null,
                    authorAvatarUrl: isset($author['avatar']) ? (string) $author['avatar'] : null,
                    text: (string) ($record['text'] ?? ''),
                    remoteCreatedAt: $createdAt,
                );
            }

            if (isset($node['replies']) && is_array($node['replies'])) {
                $this->flatten(array_values($node['replies']), $ownerDid, $uri, $since, $out);
            }
        }
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials, array $media = []): ReplyPostResult
    {
        $session = (array) ($credentials['session'] ?? []);
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);
        $jwt = (string) ($session['accessJwt'] ?? '');
        $did = $account->remote_account_id;

        $parentRef = ['uri' => $parent->remote_reply_id, 'cid' => (string) $parent->remote_cid];

        try {
            $root = $this->resolveRoot($pds, $jwt, $did, $parent, $parentRef);

            $embed = $media === [] ? null : $this->buildEmbed($media, $pds, $jwt, $did);

            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $text,
                'createdAt' => Date::now()->toIso8601String(),
                'reply' => ['root' => $root, 'parent' => $parentRef],
            ];

            if ($embed !== null) {
                $record['embed'] = $embed;
            }

            $response = $this->http->withToken($jwt)->acceptJson()
                ->post($pds.'/xrpc/com.atproto.repo.createRecord', [
                    'repo' => $did,
                    'collection' => 'app.bsky.feed.post',
                    'record' => $record,
                ]);
        } catch (BlueskyReplyMediaFailed) {
            return ReplyPostResult::failed('Could not upload media.');
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $this->mapPostFailure($response);
        }

        return ReplyPostResult::ok((string) $response->json('uri'), (string) $response->json('cid'));
    }

    public function likeReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        $session = (array) ($credentials['session'] ?? []);
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);
        $jwt = (string) ($session['accessJwt'] ?? '');

        try {
            $response = $this->http->withToken($jwt)->acceptJson()
                ->post($pds.'/xrpc/com.atproto.repo.createRecord', [
                    'repo' => $account->remote_account_id,
                    'collection' => 'app.bsky.feed.like',
                    'record' => [
                        '$type' => 'app.bsky.feed.like',
                        'subject' => ['uri' => $reply->remote_reply_id, 'cid' => (string) $reply->remote_cid],
                        'createdAt' => Date::now()->toIso8601String(),
                    ],
                ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        return $response->failed()
            ? $this->mapActionFailure($response)
            : ReplyActionResult::ok((string) $response->json('uri'));
    }

    public function unlikeReply(ConnectedAccount $account, PostTargetReply $reply, ?string $likeRemoteId, array $credentials): ReplyActionResult
    {
        if ($likeRemoteId === null) {
            return ReplyActionResult::ok();
        }

        return $this->deleteRecord($account, $credentials, 'app.bsky.feed.like', $likeRemoteId);
    }

    public function deleteReply(ConnectedAccount $account, PostTargetReply $reply, array $credentials): ReplyActionResult
    {
        return $this->deleteRecord($account, $credentials, 'app.bsky.feed.post', $reply->remote_reply_id);
    }

    /**
     * Delete a record in the user's repo by AT-URI. The rkey is the URI's final
     * path segment (at://<did>/<collection>/<rkey>).
     *
     * @param  array<string, mixed>  $credentials
     */
    private function deleteRecord(ConnectedAccount $account, array $credentials, string $collection, string $uri): ReplyActionResult
    {
        $session = (array) ($credentials['session'] ?? []);
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);
        $jwt = (string) ($session['accessJwt'] ?? '');
        $rkey = (string) (explode('/', $uri)[4] ?? '');

        if ($rkey === '') {
            return ReplyActionResult::failed('Could not resolve the record key.');
        }

        try {
            $response = $this->http->withToken($jwt)->acceptJson()
                ->post($pds.'/xrpc/com.atproto.repo.deleteRecord', [
                    'repo' => $account->remote_account_id,
                    'collection' => $collection,
                    'rkey' => $rkey,
                ]);
        } catch (ConnectionException $e) {
            return ReplyActionResult::failed($e->getMessage());
        }

        return $response->failed() ? $this->mapActionFailure($response) : ReplyActionResult::ok();
    }

    private function mapActionFailure(Response $response): ReplyActionResult
    {
        return match (true) {
            $response->status() === 401 => ReplyActionResult::authExpired($this->excerpt($response)),
            $response->status() === 429 => ReplyActionResult::rateLimited($this->excerpt($response)),
            default => ReplyActionResult::failed($this->excerpt($response)),
        };
    }

    /**
     * @param  list<PostMedia>  $media
     * @return array<string, mixed>
     */
    private function buildEmbed(array $media, string $pds, string $jwt, string $did): array
    {
        $video = array_values(array_filter($media, fn ($m) => $m->isVideo()));

        if ($video !== []) {
            return $this->videoEmbed($video[0], $pds, $jwt, $did);
        }

        return $this->imagesEmbed($media, $pds, $jwt);
    }

    /**
     * @param  list<PostMedia>  $media
     * @return array{'$type': string, images: list<array{alt: string, image: array<string, mixed>}>}
     */
    private function imagesEmbed(array $media, string $pds, string $jwt): array
    {
        $images = [];
        foreach (array_slice($media, 0, Platform::Bluesky->maxMedia()) as $item) {
            $bytes = (string) Storage::disk($item->disk)->get($item->path);
            $response = $this->http->withToken($jwt)->withBody($bytes, $item->mime)
                ->post($pds.'/xrpc/com.atproto.repo.uploadBlob');
            if ($response->failed()) {
                throw new BlueskyReplyMediaFailed($response->status());
            }
            $images[] = ['alt' => (string) ($item->alt_text ?? ''), 'image' => (array) $response->json('blob')];
        }

        return ['$type' => 'app.bsky.embed.images', 'images' => $images];
    }

    /**
     * @return array{'$type': string, video: array<string, mixed>, alt?: string}
     */
    private function videoEmbed(PostMedia $media, string $pds, string $jwt, string $did): array
    {
        $pdsHost = (string) parse_url($pds, PHP_URL_HOST);
        $auth = $this->http->withToken($jwt)->acceptJson()
            ->get($pds.'/xrpc/com.atproto.server.getServiceAuth', [
                'aud' => 'did:web:'.$pdsHost, 'lxm' => 'com.atproto.repo.uploadBlob', 'exp' => time() + 1800,
            ]);
        if ($auth->failed()) {
            throw new BlueskyReplyMediaFailed($auth->status());
        }

        $body = Utils::streamFor(Storage::disk($media->disk)->readStream($media->path));
        $upload = $this->http->withToken((string) $auth->json('token'))->withBody($body, 'video/mp4')
            ->post('https://video.bsky.app/xrpc/app.bsky.video.uploadVideo?did='.rawurlencode($did).'&name=video.mp4');
        if ($upload->failed() && $upload->json('error') !== 'already_exists') {
            throw new BlueskyReplyMediaFailed($upload->status());
        }
        $jobId = (string) $upload->json('jobId');

        // Poll to completion — safe inside the SendReply job.
        for ($i = 0; $i < 60; $i++) {
            $status = $this->http->acceptJson()
                ->get('https://video.bsky.app/xrpc/app.bsky.video.getJobStatus', ['jobId' => $jobId]);
            $state = (string) $status->json('jobStatus.state', '');
            if ($state === 'JOB_STATE_COMPLETED') {
                $embed = ['$type' => 'app.bsky.embed.video', 'video' => (array) $status->json('jobStatus.blob')];
                if (($media->alt_text ?? '') !== '') {
                    $embed['alt'] = (string) $media->alt_text;
                }

                return $embed;
            }
            if ($state === 'JOB_STATE_FAILED') {
                throw new BlueskyReplyMediaFailed(500);
            }
            usleep(2_000_000);
        }

        throw new BlueskyReplyMediaFailed(504);
    }

    /**
     * The thread root strong-ref: read the parent record's stored `reply.root`;
     * if the parent is itself the original post (no reply field), it IS the root.
     *
     * @param  array{uri: string, cid: string}  $parentRef
     * @return array{uri: string, cid: string}
     */
    private function resolveRoot(string $pds, string $jwt, string $did, PostTargetReply $parent, array $parentRef): array
    {
        // The parent reply lives in the parent author's repo, whose DID is embedded in
        // the at-uri (at://<did>/<collection>/<rkey>), not the posting user's repo.
        $segments = explode('/', $parent->remote_reply_id);
        $repoDid = $segments[2] ?? $did;
        $rkey = (string) ($segments[4] ?? '');

        $response = $this->http->withToken($jwt)->acceptJson()
            ->get($pds.'/xrpc/com.atproto.repo.getRecord', [
                'repo' => $repoDid,
                'collection' => 'app.bsky.feed.post',
                'rkey' => $rkey,
            ]);

        $root = $response->json('value.reply.root');

        return is_array($root) && isset($root['uri'], $root['cid'])
            ? ['uri' => (string) $root['uri'], 'cid' => (string) $root['cid']]
            : $parentRef;
    }

    private function mapPostFailure(Response $response): ReplyPostResult
    {
        return match (true) {
            $response->status() === 401 => ReplyPostResult::authExpired($this->excerpt($response)),
            $response->status() === 429 => ReplyPostResult::rateLimited($this->excerpt($response)),
            default => ReplyPostResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('message') ?? $response->json('error') ?? mb_substr($response->body(), 0, 200));
    }
}

/** @internal */
final class BlueskyReplyMediaFailed extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Bluesky reply media upload failed.');
    }
}
