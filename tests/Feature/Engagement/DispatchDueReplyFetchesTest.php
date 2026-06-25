<?php

// tests/Feature/Engagement/DispatchDueReplyFetchesTest.php
use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Queue;

test('it dispatches a fetch job for a recently-published target', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(2),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchPostTargetReplies::class, 1);
});

test('it skips targets published outside the window', function () {
    Queue::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'status' => ConnectedAccountStatus::Active]);
    PostTarget::factory()->for($account, 'account')->create([
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://root',
        'posted_at' => now()->subDays(30),
    ]);

    $this->artisan('engagement:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});
