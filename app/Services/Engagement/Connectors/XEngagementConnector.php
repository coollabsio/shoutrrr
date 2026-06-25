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

class XEngagementConnector implements EngagementConnector
{
    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchReplies(ConnectedAccount $account, PostTarget $target, array $credentials, ?CarbonImmutable $since): ReplyFetchResult
    {
        $rootId = $target->remote_ids[0] ?? $target->remote_id;

        if ($rootId === null) {
            return ReplyFetchResult::failed('Target has no remote id.');
        }

        $query = "conversation_id:{$rootId} -from:{$account->handle}";

        $params = [
            'query' => $query,
            'tweet.fields' => 'author_id,created_at,in_reply_to_user_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 100,
        ];

        if ($since !== null) {
            $params['start_time'] = $since->toIso8601ZuluString();
        }

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/tweets/search/recent', $params);
        } catch (ConnectionException $e) {
            return ReplyFetchResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $this->mapFetchFailure($response);
        }

        /** @var array<string, array<string, mixed>> $users */
        $users = [];
        foreach ((array) $response->json('includes.users', []) as $user) {
            $users[(string) $user['id']] = $user;
        }

        $replies = [];
        foreach ((array) $response->json('data', []) as $tweet) {
            $author = $users[(string) ($tweet['author_id'] ?? '')] ?? [];

            $replies[] = new FetchedReply(
                remoteReplyId: (string) $tweet['id'],
                remoteCid: null,
                parentRemoteId: (string) $rootId,
                authorHandle: (string) ($author['username'] ?? ''),
                authorName: isset($author['name']) ? (string) $author['name'] : null,
                authorAvatarUrl: isset($author['profile_image_url']) ? (string) $author['profile_image_url'] : null,
                text: (string) ($tweet['text'] ?? ''),
                remoteCreatedAt: isset($tweet['created_at']) ? CarbonImmutable::parse((string) $tweet['created_at']) : Date::now(),
            );
        }

        return ReplyFetchResult::ok($replies);
    }

    public function postReply(ConnectedAccount $account, PostTargetReply $parent, string $text, array $credentials, array $media = []): ReplyPostResult
    {
        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->post(self::BASE.'/tweets', [
                    'text' => $text,
                    'reply' => ['in_reply_to_tweet_id' => $parent->remote_reply_id],
                ]);
        } catch (ConnectionException $e) {
            return ReplyPostResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return match (true) {
                $response->status() === 401 => ReplyPostResult::authExpired($this->excerpt($response)),
                $response->status() === 403 => ReplyPostResult::unsupported($this->excerpt($response)),
                $response->status() === 429 => ReplyPostResult::rateLimited($this->excerpt($response)),
                default => ReplyPostResult::failed($this->excerpt($response)),
            };
        }

        return ReplyPostResult::ok((string) $response->json('data.id'));
    }

    private function mapFetchFailure(Response $response): ReplyFetchResult
    {
        return match (true) {
            $response->status() === 401 => ReplyFetchResult::authExpired($this->excerpt($response)),
            $response->status() === 403 => ReplyFetchResult::unsupported($this->excerpt($response)),
            $response->status() === 429 => ReplyFetchResult::rateLimited($this->excerpt($response)),
            default => ReplyFetchResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('title') ?? $response->json('detail') ?? mb_substr($response->body(), 0, 200));
    }
}
