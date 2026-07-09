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
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class XExternalPostImporter
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly InstanceSettings $settings,
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
                    'max_results' => 100,
                    'start_time' => $startTime->toIso8601ZuluString(),
                    'tweet.fields' => 'created_at,public_metrics',
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
        foreach ((array) $response->json('data', []) as $tweet) {
            if ($this->tweetIsBeforeStartTime((array) $tweet, $startTime)) {
                continue;
            }

            if ($this->importTweet($account, (array) $tweet)) {
                $imported++;
            }
        }

        $account->forceFill(['external_posts_synced_at' => Date::now()])->save();

        return $imported;
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function importTweet(ConnectedAccount $account, array $tweet): bool
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

        DB::transaction(function () use ($account, $authorId, $postedAt, $remoteId, $text, $tweet, $workspace): void {
            $post = Post::create([
                'workspace_id' => $workspace->id,
                'account_set_id' => null,
                'author_id' => $authorId,
                'base_text' => $text,
                'segments' => [$text],
                'mentions' => null,
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
        });

        return true;
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
