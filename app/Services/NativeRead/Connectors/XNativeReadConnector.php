<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

class XNativeReadConnector implements NativeReadConnector
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/users/'.$account->remote_account_id.'/tweets', [
                    'exclude' => 'replies,retweets',
                    'max_results' => 100,
                    'start_time' => $cursor->watermark->toIso8601ZuluString(),
                    'tweet.fields' => 'created_at,attachments,entities,note_tweet',
                    'expansions' => 'attachments.media_keys',
                    'media.fields' => 'type,url',
                ]);
        } catch (ConnectionException $e) {
            return RecentPostsResult::failed($e->getMessage());
        }

        /** @var list<array<string, mixed>> $tweets */
        $tweets = $response->successful() ? (array) $response->json('data', []) : [];

        $this->meterRead(
            UsageCategory::ExternalApi,
            UsageOperation::X_READ,
            $account,
            $response,
            array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $tweets),
        );

        if ($response->failed()) {
            return $response->status() === 429
                ? RecentPostsResult::rateLimited($response->body())
                : RecentPostsResult::failed($response->body());
        }

        // Only photos expose a direct `url`; video/GIF stay reference-less.
        $photoUrls = [];
        foreach ((array) $response->json('includes.media', []) as $media) {
            if (($media['type'] ?? null) === 'photo' && isset($media['media_key'], $media['url'])) {
                $photoUrls[(string) $media['media_key']] = (string) $media['url'];
            }
        }

        $posts = [];
        $newest = null;
        foreach ($tweets as $tweet) {
            $id = (string) ($tweet['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $newest ??= $id;
            $posts[] = new NativePost(
                remoteId: $id,
                text: $this->text($tweet),
                createdAt: Carbon::parse((string) ($tweet['created_at'] ?? 'now'))->toImmutable(),
                media: array_values(array_map(
                    static fn (string $key): NativeMedia => new NativeMedia($photoUrls[$key], 'image'),
                    array_filter((array) ($tweet['attachments']['media_keys'] ?? []), static fn (string $key): bool => isset($photoUrls[$key])),
                )),
                isReply: false,
                isRepost: false,
            );
        }

        return RecentPostsResult::ok($posts, $newest);
    }

    /**
     * X appends a t.co link per attachment and shortens every link; drop the media
     * links, expand the rest and decode the HTML-escaped text.
     *
     * @param  array<string, mixed>  $tweet
     */
    private function text(array $tweet): string
    {
        // Long posts (>280 chars) carry the full text + entities in note_tweet.
        $source = isset($tweet['note_tweet']['text']) ? $tweet['note_tweet'] : $tweet;
        $text = (string) ($source['text'] ?? '');

        foreach ((array) ($source['entities']['urls'] ?? []) as $url) {
            if (! isset($url['url'])) {
                continue;
            }
            $isMedia = isset($url['media_key']) || preg_match('#/status/\d+/(photo|video)/\d+$#', (string) ($url['expanded_url'] ?? '')) === 1;
            $text = str_replace((string) $url['url'], $isMedia ? '' : (string) ($url['expanded_url'] ?? $url['url']), $text);
        }

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
