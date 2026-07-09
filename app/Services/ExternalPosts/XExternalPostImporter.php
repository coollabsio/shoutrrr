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
                    'tweet.fields' => 'attachments,author_id,created_at,entities,public_metrics,referenced_tweets',
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
                $this->enrichPost($post, $existing, $tweet, $includes);
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

        $text = $this->cleanTweetText($tweet);
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
    private function enrichPost(Post $post, PostTarget $target, array $tweet, array $includes): void
    {
        $text = $this->cleanTweetText($tweet);
        $externalContext = $this->externalContext($tweet, $includes);
        $postUpdates = [
            'base_text' => $text,
            'segments' => [$text],
        ];

        if ($externalContext !== null) {
            $postUpdates['external_context'] = $externalContext;
        }

        $post->forceFill($postUpdates)->save();
        $target->forceFill(['sections' => [$text]])->save();

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
                    'text' => $quotedTweet === [] ? '' : $this->cleanTweetText($quotedTweet),
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
     * X includes generated t.co URLs in the text for attached media and quoted
     * tweets. Keep real user links, but remove those generated card URLs.
     *
     * @param  array<string, mixed>  $tweet
     */
    private function cleanTweetText(array $tweet): string
    {
        $text = (string) ($tweet['text'] ?? '');
        if ($text === '') {
            return '';
        }

        $ranges = $this->removableUrlRanges($tweet, $text);
        if ($ranges === []) {
            return $this->fallbackCleanTrailingCardUrls($tweet, $text);
        }

        usort(
            $ranges,
            static fn (array $a, array $b): int => $b['start'] <=> $a['start'],
        );

        foreach ($ranges as $range) {
            $text = mb_substr($text, 0, $range['start'])
                .mb_substr($text, $range['end']);
        }

        return $this->normalizeImportedText($text);
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @return list<array{start: int, end: int}>
     */
    private function removableUrlRanges(array $tweet, string $text): array
    {
        $urls = $tweet['entities']['urls'] ?? [];
        if (! is_array($urls)) {
            return [];
        }

        $ranges = [];
        $trimmedLength = mb_strlen(rtrim($text));
        foreach ($urls as $url) {
            if (
                ! is_array($url)
                || ! isset($url['start'], $url['end'])
                || ! is_numeric($url['start'])
                || ! is_numeric($url['end'])
            ) {
                continue;
            }

            $start = (int) $url['start'];
            $end = (int) $url['end'];
            if ($start < 0 || $end <= $start || $end > mb_strlen($text)) {
                continue;
            }

            if ($this->isGeneratedCardUrl($tweet, $url, $end, $trimmedLength)) {
                $ranges[] = ['start' => $start, 'end' => $end];
            }
        }

        return $ranges;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array<string, mixed>  $url
     */
    private function isGeneratedCardUrl(array $tweet, array $url, int $end, int $trimmedLength): bool
    {
        $displayUrl = strtolower((string) ($url['display_url'] ?? ''));
        $expandedUrl = strtolower((string) ($url['expanded_url'] ?? ''));
        $shortUrl = strtolower((string) ($url['url'] ?? ''));

        if (
            str_starts_with($displayUrl, 'pic.x.com/')
            || str_starts_with($displayUrl, 'pic.twitter.com/')
            || str_contains($expandedUrl, '/photo/')
            || str_contains($expandedUrl, '/video/')
        ) {
            return true;
        }

        $quotedTweetId = $this->quotedTweetId($tweet);
        if (
            $quotedTweetId !== null
            && (
                str_contains($expandedUrl, '/status/'.$quotedTweetId)
                || str_contains($expandedUrl, '/i/web/status/'.$quotedTweetId)
            )
        ) {
            return true;
        }

        return $end === $trimmedLength
            && str_starts_with($shortUrl, 'https://t.co/')
            && ($this->tweetHasMedia($tweet) || $quotedTweetId !== null)
            && (
                str_contains($displayUrl, 'x.com/')
                || str_contains($displayUrl, 'twitter.com/')
                || str_contains($expandedUrl, 'x.com/')
                || str_contains($expandedUrl, 'twitter.com/')
            );
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function fallbackCleanTrailingCardUrls(array $tweet, string $text): string
    {
        if (! $this->tweetHasMedia($tweet) && $this->quotedTweetId($tweet) === null) {
            return $this->normalizeImportedText($text);
        }

        return $this->normalizeImportedText(
            (string) preg_replace('/(?:\s+https:\/\/t\.co\/\S+)+\s*$/u', '', $text),
        );
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function tweetHasMedia(array $tweet): bool
    {
        $attachments = $tweet['attachments'] ?? [];

        return is_array($attachments)
            && isset($attachments['media_keys'])
            && is_array($attachments['media_keys'])
            && $attachments['media_keys'] !== [];
    }

    private function normalizeImportedText(string $text): string
    {
        $text = (string) preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
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
