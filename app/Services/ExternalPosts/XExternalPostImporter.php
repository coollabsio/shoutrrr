<?php

declare(strict_types=1);

namespace App\Services\ExternalPosts;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\MediaStorageService;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class XExternalPostImporter
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly InstanceSettings $settings,
        private readonly MediaStorageService $mediaStorage,
    ) {}

    /**
     * Import recent original posts authored directly on X for a connected account.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function import(ConnectedAccount $account, array $credentials): int
    {
        if ($account->platform !== Platform::X) {
            return 0;
        }

        $startTime = $this->syncStartTime();

        try {
            $response = $this->http
                ->withToken((string) ($credentials['access_token'] ?? ''))
                ->acceptJson()
                ->get(self::BASE.'/users/'.$account->remote_account_id.'/tweets', [
                    'exclude' => 'retweets,replies',
                    'expansions' => 'attachments.media_keys,referenced_tweets.id,referenced_tweets.id.author_id,referenced_tweets.id.attachments.media_keys',
                    'max_results' => 100,
                    'media.fields' => 'media_key,type,url,preview_image_url,alt_text,width,height,duration_ms',
                    'start_time' => $startTime->toIso8601ZuluString(),
                    'tweet.fields' => 'attachments,author_id,created_at,public_metrics,referenced_tweets',
                    'user.fields' => 'id,name,username,profile_image_url',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not sync external X posts.', [
                'account_id' => $account->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::EXTERNAL_POSTS_FETCH, $account, $response);

        if ($response->failed()) {
            Log::warning('External X posts sync failed.', [
                'account_id' => $account->id,
                'status' => $response->status(),
                'message' => (string) ($response->json('title') ?? $response->json('detail') ?? mb_substr($response->body(), 0, 200)),
            ]);

            return 0;
        }

        $imported = 0;
        $includes = $this->indexedIncludes((array) $response->json('includes', []));
        foreach ((array) $response->json('data', []) as $tweet) {
            if ($this->tweetIsBeforeStartTime((array) $tweet, $startTime)) {
                continue;
            }

            if ($this->importTweet($account, (array) $tweet, $includes)) {
                $imported++;
            }
        }

        $account->forceFill(['external_posts_synced_at' => Date::now()])->save();

        return $imported;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $includes
     */
    private function importTweet(ConnectedAccount $account, array $tweet, array $includes): bool
    {
        $remoteId = isset($tweet['id']) ? (string) $tweet['id'] : '';
        if ($remoteId === '') {
            return false;
        }

        $existing = PostTarget::query()
            ->where('connected_account_id', $account->id)
            ->where('remote_id', $remoteId)
            ->first();

        if ($existing !== null) {
            $this->applyMetrics($existing, (array) ($tweet['public_metrics'] ?? []));
            $post = $existing->post()->first();
            if ($post !== null) {
                $this->enrichPost($post, $tweet, $includes);
            }

            return false;
        }

        $workspace = $account->workspace()->first();
        $authorId = $account->connected_by_user_id ?? $workspace?->owner_id;
        if ($workspace === null || $authorId === null) {
            Log::warning('Skipped external X post import without a local author.', [
                'account_id' => $account->id,
                'remote_id' => $remoteId,
            ]);

            return false;
        }

        $text = (string) ($tweet['text'] ?? '');
        $postedAt = isset($tweet['created_at'])
            ? CarbonImmutable::parse((string) $tweet['created_at'])
            : CarbonImmutable::instance(Date::now());
        $externalContext = $this->externalContext($tweet, $includes);

        $post = DB::transaction(function () use ($account, $authorId, $externalContext, $postedAt, $remoteId, $text, $tweet, $workspace): Post {
            $post = Post::create([
                'workspace_id' => $workspace->id,
                'account_set_id' => null,
                'author_id' => $authorId,
                'base_text' => $text,
                'segments' => [$text],
                'mentions' => null,
                'external_context' => $externalContext,
                'status' => PostStatus::Published->value,
                'scheduled_at' => null,
                'published_at' => $postedAt,
                'deleted_at' => null,
            ]);

            $target = PostTarget::create([
                'post_id' => $post->id,
                'connected_account_id' => $account->id,
                'platform' => Platform::X->value,
                'sections' => [$text],
                'content_override' => null,
                'auto_split' => false,
                'status' => PostTargetStatus::Published->value,
                'remote_id' => $remoteId,
                'remote_ids' => [$remoteId],
                'imported_from_remote' => true,
                'posted_at' => $postedAt,
                'metrics_status' => MetricsStatus::Ok->value,
                'metrics_captured_at' => Date::now(),
            ]);

            $this->applyMetrics($target, (array) ($tweet['public_metrics'] ?? []));

            return $post;
        });

        $this->attachTweetMedia($post, $tweet, $includes);

        return true;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $includes
     */
    private function enrichPost(Post $post, array $tweet, array $includes): void
    {
        $externalContext = $this->externalContext($tweet, $includes);
        if ($externalContext !== null) {
            $post->forceFill(['external_context' => $externalContext])->save();
        }

        $this->attachTweetMedia($post, $tweet, $includes);
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $includes
     */
    private function attachTweetMedia(Post $post, array $tweet, array $includes): void
    {
        if ($post->media()->exists()) {
            return;
        }

        $position = 0;
        foreach (array_slice($this->mediaForTweet($tweet, $includes), 0, 4) as $media) {
            $url = $this->mediaPreviewUrl($media);
            if ($url === null) {
                continue;
            }

            try {
                $stored = $this->mediaStorage->storeFromUrl(
                    $post->workspace_id,
                    $url,
                    isset($media['alt_text']) ? (string) $media['alt_text'] : null,
                );
            } catch (Throwable $exception) {
                Log::warning('Skipped external X post media import.', [
                    'post_id' => $post->id,
                    'media_url' => $url,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $stored->forceFill([
                'post_id' => $post->id,
                'position' => $position,
            ])->save();

            $position++;
        }
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $includes
     * @return array{x: array{quoted_tweet: array{id: string, text: string, author_name: string|null, author_username: string|null, author_avatar_url: string|null, media: list<array{type: string, url: string, alt_text: string|null, width: int|null, height: int|null}>}}}|null
     */
    private function externalContext(array $tweet, array $includes): ?array
    {
        $quotedTweetId = $this->quotedTweetId($tweet);
        if ($quotedTweetId === null) {
            return null;
        }

        $quotedTweet = $includes['tweets'][$quotedTweetId] ?? [];
        $author = [];
        if (isset($quotedTweet['author_id'])) {
            $author = $includes['users'][(string) $quotedTweet['author_id']] ?? [];
        }

        return [
            'x' => [
                'quoted_tweet' => [
                    'id' => $quotedTweetId,
                    'text' => (string) ($quotedTweet['text'] ?? ''),
                    'author_name' => isset($author['name']) ? (string) $author['name'] : null,
                    'author_username' => isset($author['username']) ? (string) $author['username'] : null,
                    'author_avatar_url' => isset($author['profile_image_url']) ? (string) $author['profile_image_url'] : null,
                    'media' => $quotedTweet === [] ? [] : $this->mediaContextForTweet($quotedTweet, $includes),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function quotedTweetId(array $tweet): ?string
    {
        $references = $tweet['referenced_tweets'] ?? [];
        if (! is_array($references)) {
            return null;
        }

        foreach ($references as $reference) {
            if (
                is_array($reference)
                && ($reference['type'] ?? null) === 'quoted'
                && isset($reference['id'])
            ) {
                return (string) $reference['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $includes
     * @return list<array<string, mixed>>
     */
    private function mediaForTweet(array $tweet, array $includes): array
    {
        $attachments = $tweet['attachments'] ?? [];
        if (! is_array($attachments)) {
            return [];
        }

        $keys = $attachments['media_keys'] ?? [];
        if (! is_array($keys)) {
            return [];
        }

        $media = [];
        foreach ($keys as $key) {
            $item = $includes['media'][(string) $key] ?? null;
            if ($item !== null) {
                $media[] = $item;
            }
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $includes
     * @return list<array{type: string, url: string, alt_text: string|null, width: int|null, height: int|null}>
     */
    private function mediaContextForTweet(array $tweet, array $includes): array
    {
        $media = [];
        foreach (array_slice($this->mediaForTweet($tweet, $includes), 0, 4) as $item) {
            $url = $this->mediaPreviewUrl($item);
            if ($url === null) {
                continue;
            }

            $media[] = [
                'type' => (string) ($item['type'] ?? 'photo'),
                'url' => $url,
                'alt_text' => isset($item['alt_text']) ? (string) $item['alt_text'] : null,
                'width' => isset($item['width']) ? (int) $item['width'] : null,
                'height' => isset($item['height']) ? (int) $item['height'] : null,
            ];
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function mediaPreviewUrl(array $media): ?string
    {
        foreach (['url', 'preview_image_url'] as $field) {
            if (isset($media[$field]) && is_string($media[$field]) && $media[$field] !== '') {
                return $media[$field];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $includes
     * @return array{media: array<string, array<string, mixed>>, tweets: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}
     */
    private function indexedIncludes(array $includes): array
    {
        return [
            'media' => $this->indexIncludeRows((array) ($includes['media'] ?? []), 'media_key'),
            'tweets' => $this->indexIncludeRows((array) ($includes['tweets'] ?? []), 'id'),
            'users' => $this->indexIncludeRows((array) ($includes['users'] ?? []), 'id'),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexIncludeRows(array $rows, string $keyField): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$keyField])) {
                continue;
            }

            $indexed[(string) $row[$keyField]] = $row;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function applyMetrics(PostTarget $target, array $metrics): void
    {
        $target->forceFill([
            'likes' => (int) ($metrics['like_count'] ?? 0),
            'comments' => (int) ($metrics['reply_count'] ?? 0),
            'reposts' => (int) ($metrics['retweet_count'] ?? 0) + (int) ($metrics['quote_count'] ?? 0),
            'impressions' => isset($metrics['impression_count']) ? (int) $metrics['impression_count'] : null,
            'metrics_status' => MetricsStatus::Ok->value,
            'metrics_captured_at' => Date::now(),
        ])->save();
    }

    private function syncStartTime(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())
            ->subDays($this->settings->externalPostsSyncLookbackDays())
            ->utc();
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function tweetIsBeforeStartTime(array $tweet, CarbonImmutable $startTime): bool
    {
        if (! isset($tweet['created_at'])) {
            return false;
        }

        return CarbonImmutable::parse((string) $tweet['created_at'])->lt($startTime);
    }
}
