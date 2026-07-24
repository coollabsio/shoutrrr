<?php

use App\Console\Commands\DispatchDueReposts;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\RepostPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;

function publishedRepostCandidate(array $capabilities, array $targetOverrides = [], ?bool $override = true): PostTarget
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => 'U', 'capabilities' => $capabilities]);
    $post = Post::factory()->create(['auto_repost' => $override]);

    return PostTarget::factory()->create(array_merge([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'T'.uniqid(),
        'posted_at' => Date::now()->subHours(200),
        'reposted_at' => null,
    ], $targetOverrides));
}

beforeEach(fn () => Queue::fake());

test('dispatches a repost job for an eligible enabled target', function (): void {
    publishedRepostCandidate(['auto_repost' => ['enabled' => true]]);

    app(DispatchDueReposts::class)->handle(app(App\Services\Repost\RepostEligibility::class));

    Queue::assertPushed(RepostPostTarget::class, 1);
});

test('skips accounts with auto-repost disabled', function (): void {
    publishedRepostCandidate(['auto_repost' => ['enabled' => false]]);

    app(DispatchDueReposts::class)->handle(app(App\Services\Repost\RepostEligibility::class));

    Queue::assertNothingPushed();
});

test('skips already-reposted targets', function (): void {
    publishedRepostCandidate(['auto_repost' => ['enabled' => true]], ['reposted_at' => Date::now()->subHour()]);

    app(DispatchDueReposts::class)->handle(app(App\Services\Repost\RepostEligibility::class));

    Queue::assertNothingPushed();
});

test('skips posts older than the backfill window', function (): void {
    config(['repost.max_backfill_days' => 30]);
    publishedRepostCandidate(['auto_repost' => ['enabled' => true]], ['posted_at' => Date::now()->subDays(60)]);

    app(DispatchDueReposts::class)->handle(app(App\Services\Repost\RepostEligibility::class));

    Queue::assertNothingPushed();
});

test('does nothing when the feature is disabled', function (): void {
    config(['repost.enabled' => false]);
    publishedRepostCandidate(['auto_repost' => ['enabled' => true]]);

    app(DispatchDueReposts::class)->handle(app(App\Services\Repost\RepostEligibility::class));

    Queue::assertNothingPushed();
});
