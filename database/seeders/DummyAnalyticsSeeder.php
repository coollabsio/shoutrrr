<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds follower history + published posts with engagement so the analytics
 * dashboard can be exercised locally without waiting on real metrics polling.
 *
 * Creates ~90 days of account metrics for every platform that has followers
 * (Discord is omitted — webhooks have no member/follower stats), and 12
 * published posts with targets on those platforms for top/bottom comparison.
 *
 * Run via `composer dev`, `php artisan db:seed` (local), or:
 * php artisan db:seed --class=DummyAnalyticsSeeder
 */
class DummyAnalyticsSeeder extends Seeder
{
    public const int POST_COUNT = 12;

    public const int HISTORY_DAYS = 90;

    private const string POST_MARKER = '[dummy-analytics]';

    private const string LEGACY_DISCORD_REMOTE_ACCOUNT_ID = 'discord-webhook-analytics-1';

    /**
     * @var list<string>
     */
    private const array SAMPLE_POSTS = [
        'Shipped keyboard triage for the engagement inbox.',
        'Follower growth is compounding — analytics finally readable.',
        'Self-hosting social should not mean five native apps.',
        'Volume backups landed. Long-awaited, finally here.',
        'MCP tools for bulk archive are on the shortlist.',
        'LinkedIn comments need better sorting — working on it.',
        'Bluesky replies feel snappy; matching that on X next.',
        'Dark-mode polish on the analytics charts this week.',
        'Programmatic scheduling API: early access soon.',
        'Sticky reply input for long threads is chef\'s kiss.',
        'Bulk archive after responding — small win, big time save.',
        'Rate limits when many workspaces share an account: documented.',
        'Quote-posts vs direct replies: treating them distinctly now.',
        'Community stats on the dashboard refreshed overnight.',
    ];

    public function run(): void
    {
        $workspace = Workspace::query()->where('slug', 'test-workspace')->first()
            ?? Workspace::query()->first();

        if ($workspace === null) {
            $this->command->warn('No workspace found — run DefaultUserSeeder first.');

            return;
        }

        $author = User::query()->find($workspace->owner_id) ?? User::query()->first();
        $this->clearPrevious($workspace);

        $accounts = $this->accounts($workspace, $author);
        $metricRows = 0;

        foreach ($accounts as $index => $account) {
            $metricRows += $this->seedAccountHistory($account, $index);
        }

        $postCount = $this->seedPosts($workspace, $author, $accounts);

        $this->command->info(
            "Seeded analytics: {$metricRows} account metric rows across {$accounts->count()} accounts, {$postCount} published posts into '{$workspace->name}'.",
        );
    }

    /**
     * One active connected account per launched platform for the analytics desk.
     *
     * @return list<array{platform: Platform, handle: string, display_name: string, remote_account_id: string, auth_method: string, base_followers: int, base_following: int}>
     */
    public static function accountSpecs(): array
    {
        return [
            [
                'platform' => Platform::X,
                'handle' => '@acme',
                'display_name' => 'Acme',
                'remote_account_id' => 'x-analytics-acme',
                'auth_method' => 'oauth',
                'base_followers' => 4_200,
                'base_following' => 380,
            ],
            [
                'platform' => Platform::Bluesky,
                'handle' => '@acme.bsky.social',
                'display_name' => 'Acme',
                'remote_account_id' => 'did:plc:acmeanalytics0001',
                'auth_method' => 'app_password',
                'base_followers' => 1_850,
                'base_following' => 210,
            ],
            [
                'platform' => Platform::LinkedIn,
                'handle' => 'acme-inc',
                'display_name' => 'Acme Inc',
                'remote_account_id' => 'linkedin-analytics-acme',
                'auth_method' => 'oauth',
                'base_followers' => 9_600,
                'base_following' => 920,
            ],
            [
                'platform' => Platform::Facebook,
                'handle' => 'Acme',
                'display_name' => 'Acme Page',
                'remote_account_id' => 'fb-page-analytics-1',
                'auth_method' => 'oauth',
                'base_followers' => 14_200,
                'base_following' => 0,
            ],
            [
                'platform' => Platform::Instagram,
                'handle' => '@acme',
                'display_name' => 'Acme',
                'remote_account_id' => 'ig-user-analytics-1',
                'auth_method' => 'oauth',
                'base_followers' => 22_400,
                'base_following' => 640,
            ],
            [
                'platform' => Platform::Threads,
                'handle' => '@acme',
                'display_name' => 'Acme',
                'remote_account_id' => 'th-user-analytics-1',
                'auth_method' => 'oauth',
                'base_followers' => 3_100,
                'base_following' => 290,
            ],
        ];
    }

    public static function platformCount(): int
    {
        return count(self::accountSpecs());
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function accounts(Workspace $workspace, ?User $author): Collection
    {
        return collect(self::accountSpecs())->map(
            function (array $spec) use ($workspace, $author): ConnectedAccount {
                /** @var Platform $platform */
                $platform = $spec['platform'];

                /** @var ConnectedAccount $account */
                $account = ConnectedAccount::query()->firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'platform' => $platform->value,
                        'handle' => $spec['handle'],
                    ],
                    [
                        'display_name' => $spec['display_name'],
                        'avatar_url' => 'https://api.dicebear.com/9.x/initials/svg?seed='.urlencode($spec['display_name']).'&backgroundType=gradientLinear',
                        'remote_account_id' => $spec['remote_account_id'],
                        'auth_method' => $spec['auth_method'],
                        'connected_by_user_id' => $author?->id,
                        'status' => ConnectedAccountStatus::Active->value,
                        'capabilities' => $platform === Platform::Instagram
                            ? ['page_id' => 'fb-page-analytics-1']
                            : null,
                    ],
                );

                // Local dummy charts always have series data, even when the live
                // connector reports unsupported (LinkedIn account metrics).
                $account->forceFill([
                    'disabled_at' => now(),
                    'metrics_status' => MetricsStatus::Ok->value,
                    'metrics_captured_at' => now()->subHours(2),
                ])->save();

                return $account->refresh();
            },
        )->values();
    }

    private function seedAccountHistory(ConnectedAccount $account, int $accountIndex): int
    {
        $spec = collect(self::accountSpecs())
            ->first(fn (array $row): bool => $row['platform'] === $account->platform);

        $baseFollowers = (int) ($spec['base_followers'] ?? 1_000);
        $baseFollowing = (int) ($spec['base_following'] ?? 150);
        $postsCount = 40 + ($accountIndex * 12);
        $rows = 0;

        for ($day = self::HISTORY_DAYS; $day >= 0; $day--) {
            // Gentle growth with a mid-range bump so charts are not a flat line.
            $progress = (self::HISTORY_DAYS - $day) / self::HISTORY_DAYS;
            $wave = sin($progress * M_PI * 2) * 18;
            $followers = (int) round($baseFollowers + ($progress * 420) + $wave + ($accountIndex * 35));
            $following = (int) round($baseFollowing + ($progress * 22) + ($accountIndex * 4));
            $capturedAt = now()->subDays($day)->setTime(9 + ($accountIndex % 5), 15);

            AccountMetric::query()->create([
                'connected_account_id' => $account->id,
                'captured_at' => $capturedAt,
                'followers' => max(0, $followers),
                'following' => max(0, $following),
                'posts_count' => $postsCount + (int) floor($progress * 8),
                'raw' => null,
            ]);
            $rows++;
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ConnectedAccount>  $accounts
     */
    private function seedPosts(Workspace $workspace, ?User $author, Collection $accounts): int
    {
        $created = 0;

        for ($i = 0; $i < self::POST_COUNT; $i++) {
            $text = self::SAMPLE_POSTS[$i % count(self::SAMPLE_POSTS)].' '.self::POST_MARKER;
            // Spread posts across the history window so range filters matter.
            $publishedAt = now()
                ->subDays((int) round(($i / max(1, self::POST_COUNT - 1)) * (self::HISTORY_DAYS - 5)))
                ->subHours($i * 2)
                ->setTime(11, 0);

            /** @var Post $post */
            $post = Post::query()->create([
                'workspace_id' => $workspace->id,
                'account_set_id' => null,
                'author_id' => $author?->id,
                'base_text' => $text,
                'segments' => [$text],
                'mentions' => null,
                'status' => PostStatus::Published->value,
                'published_at' => $publishedAt,
            ]);

            // Vary engagement so top/bottom comparison has a clear ranking.
            $engagementScale = match (true) {
                $i < 3 => 1.0 - ($i * 0.12),
                $i >= self::POST_COUNT - 3 => 0.08 + (($i - (self::POST_COUNT - 3)) * 0.03),
                default => 0.25 + (($i % 5) * 0.08),
            };

            foreach ($accounts as $accountIndex => $account) {
                $likes = (int) round((180 + ($i * 37) + ($accountIndex * 21)) * $engagementScale);
                $comments = (int) round((24 + ($i * 5) + ($accountIndex * 3)) * $engagementScale);
                $reposts = (int) round((12 + ($i * 3) + $accountIndex) * $engagementScale);
                $impressions = $this->supportsImpressions($account->platform)
                    ? (int) round((2_400 + ($i * 310) + ($accountIndex * 180)) * $engagementScale)
                    : null;

                $capturedAt = $publishedAt->copy()->addHours(18 + $accountIndex);

                /** @var PostTarget $target */
                $target = PostTarget::query()->create([
                    'post_id' => $post->id,
                    'connected_account_id' => $account->id,
                    'platform' => $account->platform->value,
                    'sections' => [$text],
                    'auto_split' => false,
                    'status' => PostTargetStatus::Published->value,
                    'remote_id' => $this->remotePostId($account->platform, $i, $accountIndex),
                    'posted_at' => $publishedAt,
                    'likes' => $likes,
                    'comments' => $comments,
                    'reposts' => $reposts,
                    'impressions' => $impressions,
                    'metrics_status' => MetricsStatus::Ok->value,
                    'metrics_captured_at' => $capturedAt,
                ]);

                foreach ([0.55, 0.8, 1.0] as $step => $factor) {
                    PostTargetMetric::query()->create([
                        'post_target_id' => $target->id,
                        'captured_at' => $capturedAt->copy()->subHours((2 - $step) * 10),
                        'likes' => (int) round($likes * $factor),
                        'comments' => (int) round($comments * $factor),
                        'reposts' => (int) round($reposts * $factor),
                        'impressions' => $impressions === null
                            ? null
                            : (int) round($impressions * $factor),
                    ]);
                }
            }

            $created++;
        }

        return $created;
    }

    private function supportsImpressions(Platform $platform): bool
    {
        // Bluesky does not surface impression counts.
        return $platform !== Platform::Bluesky;
    }

    private function remotePostId(Platform $platform, int $postIndex, int $accountIndex): string
    {
        return match ($platform) {
            Platform::X => (string) (1_710_000_000_000_000_000 + ($postIndex * 100) + $accountIndex),
            Platform::LinkedIn => 'urn:li:share:'.(7_100_000_000_000_000_000 + ($postIndex * 10) + $accountIndex),
            Platform::Bluesky => 'at://did:plc:acmeanalytics/app.bsky.feed.post/dummy'.$postIndex.$accountIndex,
            Platform::Facebook => 'fb_post_'.$postIndex.'_'.$accountIndex,
            Platform::Instagram => 'ig_media_'.$postIndex.'_'.$accountIndex,
            Platform::Threads => 'th_media_'.$postIndex.'_'.$accountIndex,
            default => 'remote-analytics-'.$platform->value.'-'.$postIndex.'-'.$accountIndex,
        };
    }

    private function clearPrevious(Workspace $workspace): void
    {
        ConnectedAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('platform', Platform::Discord->value)
            ->where('remote_account_id', self::LEGACY_DISCORD_REMOTE_ACCOUNT_ID)
            ->update(['disabled_at' => now()]);

        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->where('base_text', 'like', '%'.self::POST_MARKER.'%')
            ->get();

        $handlesByPlatform = collect(self::accountSpecs())
            ->mapWithKeys(fn (array $spec): array => [$spec['platform']->value => $spec['handle']]);

        $accountIds = ConnectedAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($handlesByPlatform): void {
                foreach ($handlesByPlatform as $platform => $handle) {
                    $query->orWhere(function ($q) use ($platform, $handle): void {
                        $q->where('platform', $platform)->where('handle', $handle);
                    });
                }
            })
            ->pluck('id');

        // Also drop any leftover Discord follower rows from older seeders — Discord
        // communities are not tracked as follower series.
        $discordIds = ConnectedAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('platform', Platform::Discord->value)
            ->pluck('id');

        $metricAccountIds = $accountIds->merge($discordIds)->unique()->values();

        if ($metricAccountIds->isNotEmpty()) {
            AccountMetric::query()->whereIn('connected_account_id', $metricAccountIds)->delete();
        }

        foreach ($posts as $post) {
            $targetIds = $post->targets()->pluck('id');
            PostTargetMetric::query()->whereIn('post_target_id', $targetIds)->delete();
            $post->targets()->delete();
            $post->delete();
        }
    }
}
