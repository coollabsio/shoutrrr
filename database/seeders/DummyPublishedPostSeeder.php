<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Builds one fully published post (X thread + LinkedIn + Bluesky) with media and
 * captured metrics so the published-post detail view can be previewed locally.
 *
 * Run with: php artisan db:seed --class=DummyPublishedPostSeeder
 */
class DummyPublishedPostSeeder extends Seeder
{
    private const string BASE_TEXT = 'Shipping our new published-post view — see exactly how each post landed and how it’s performing, all in one place. ✨';

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

        $mediaPath = $this->ensureMediaFile();

        /** @var Post $post */
        $post = Post::query()->create([
            'workspace_id' => $workspace->id,
            'account_set_id' => null,
            'author_id' => $author?->id,
            'base_text' => self::BASE_TEXT,
            'segments' => [self::BASE_TEXT],
            'mentions' => null,
            'status' => PostStatus::Published->value,
            'published_at' => now()->subDays(2),
        ]);

        PostMedia::query()->create([
            'workspace_id' => $workspace->id,
            'post_id' => $post->id,
            'disk' => 'public',
            'path' => $mediaPath,
            'mime' => 'image/png',
            'size_bytes' => 120_000,
            'width' => 1200,
            'height' => 675,
            'alt_text' => 'Product announcement graphic',
            'position' => 0,
            'kind' => 'image',
        ]);

        $this->target($post, $this->account($workspace, $author, Platform::X, '@acme', 'Acme'), [
            'sections' => [
                'Shipping our new published-post view 🚀',
                'See exactly how each post landed across every network — and how it’s performing — without leaving the app.',
            ],
            'remote_id' => '1789012345678901234',
            'likes' => 824,
            'comments' => 213,
            'reposts' => 57,
            'impressions' => 12_450,
        ]);

        $this->target($post, $this->account($workspace, $author, Platform::LinkedIn, 'acme-inc', 'Acme Inc'), [
            'sections' => [self::BASE_TEXT],
            'remote_id' => 'urn:li:share:7012345678901234567',
            'likes' => 386,
            'comments' => 132,
            'reposts' => 34,
            'impressions' => 5_210,
        ]);

        $this->target($post, $this->account($workspace, $author, Platform::Bluesky, '@acme.bsky.social', 'Acme'), [
            'sections' => [self::BASE_TEXT],
            'remote_id' => 'at://did:plc:acmedummyaccount/app.bsky.feed.post/3kdummyrkey1',
            'likes' => 142,
            'comments' => 38,
            'reposts' => 19,
            'impressions' => null,
        ]);

        $this->command->info('Dummy published post ready: '.route('posts.show', $post->id));
    }

    /**
     * @param  array{sections: list<string>, remote_id: string, likes: int, comments: int, reposts: int, impressions: int|null}  $data
     */
    private function target(Post $post, ConnectedAccount $account, array $data): void
    {
        $capturedAt = now()->subMinutes(18);

        /** @var PostTarget $target */
        $target = PostTarget::query()->create([
            'post_id' => $post->id,
            'connected_account_id' => $account->id,
            'platform' => $account->platform->value,
            'sections' => $data['sections'],
            'auto_split' => count($data['sections']) > 1,
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $data['remote_id'],
            'posted_at' => $post->published_at,
            'likes' => $data['likes'],
            'comments' => $data['comments'],
            'reposts' => $data['reposts'],
            'impressions' => $data['impressions'],
            'metrics_status' => MetricsStatus::Ok->value,
            'metrics_captured_at' => $capturedAt,
        ]);

        // A short history so a refresh has something to compare against.
        foreach ([0.6, 0.85, 1.0] as $index => $factor) {
            PostTargetMetric::query()->create([
                'post_target_id' => $target->id,
                'captured_at' => $capturedAt->copy()->subHours((2 - $index) * 12),
                'likes' => (int) round($data['likes'] * $factor),
                'comments' => (int) round($data['comments'] * $factor),
                'reposts' => (int) round($data['reposts'] * $factor),
                'impressions' => $data['impressions'] === null
                    ? null
                    : (int) round($data['impressions'] * $factor),
            ]);
        }
    }

    private function account(
        Workspace $workspace,
        ?User $author,
        Platform $platform,
        string $handle,
        string $displayName,
    ): ConnectedAccount {
        return ConnectedAccount::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'platform' => $platform->value,
                'handle' => $handle,
            ],
            [
                'display_name' => $displayName,
                'avatar_url' => 'https://api.dicebear.com/9.x/initials/svg?seed='.urlencode($displayName).'&backgroundType=gradientLinear',
                'remote_account_id' => $platform->value.'-dummy-'.$workspace->id,
                'auth_method' => $platform === Platform::Bluesky ? 'app_password' : 'oauth',
                'connected_by_user_id' => $author?->id,
                'status' => ConnectedAccountStatus::Active->value,
            ],
        );
    }

    private function ensureMediaFile(): string
    {
        $path = 'media/dummy-published-post.png';

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, (string) file_get_contents(public_path('shoutrrr.png')));
        }

        return $path;
    }

    private function clearPrevious(Workspace $workspace): void
    {
        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->where('base_text', self::BASE_TEXT)
            ->get();

        foreach ($posts as $post) {
            $targetIds = $post->targets()->pluck('id');
            PostTargetMetric::query()->whereIn('post_target_id', $targetIds)->delete();
            $post->targets()->delete();
            $post->media()->delete();
            $post->delete();
        }
    }
}
