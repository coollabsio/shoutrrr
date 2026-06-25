<?php

declare(strict_types=1);

namespace App\Services\Engagement\Connectors;

use App\Dto\Engagement\FetchedReply;
use App\Dto\Engagement\ReplyFetchResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Contracts\EngagementConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;

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

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials): ReplyPostResult
    {
        $session = (array) ($credentials['session'] ?? []);
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);
        $jwt = (string) ($session['accessJwt'] ?? '');
        $did = $account->remote_account_id;

        $parentRef = ['uri' => $parent->remote_reply_id, 'cid' => (string) $parent->remote_cid];

        try {
            $root = $this->resolveRoot($pds, $jwt, $did, $parent, $parentRef);

            $response = $this->http->withToken($jwt)->acceptJson()
                ->post($pds.'/xrpc/com.atproto.repo.createRecord', [
                    'repo' => $did,
                    'collection' => 'app.bsky.feed.post',
                    'record' => [
                        '$type' => 'app.bsky.feed.post',
                        'text' => $text,
                        'createdAt' => Date::now()->toIso8601String(),
                        'reply' => ['root' => $root, 'parent' => $parentRef],
                    ],
                ]);
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $this->mapPostFailure($response);
        }

        return ReplyPostResult::ok((string) $response->json('uri'), (string) $response->json('cid'));
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
