<?php

use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Bus;

test('it claims due scheduled posts and dispatches their targets', function () {
    Bus::fake();

    $due = Post::factory()->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);
    PostTarget::factory()->for($due)->create();

    $future = Post::factory()->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addHour()]);
    PostTarget::factory()->for($future)->create();

    $this->artisan('posts:dispatch-due')->assertExitCode(0);

    expect($due->refresh()->status)->toBe(PostStatus::Publishing)
        ->and($future->refresh()->status)->toBe(PostStatus::Scheduled);

    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
});

test('a second immediate run does not re-dispatch a post this command already claimed', function () {
    Bus::fake();

    $due = Post::factory()->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);
    PostTarget::factory()->for($due)->create();

    $this->artisan('posts:dispatch-due')->assertExitCode(0);
    $this->artisan('posts:dispatch-due')->assertExitCode(0);

    expect($due->refresh()->status)->toBe(PostStatus::Publishing);
    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
});

test('a second run does not double-dispatch an already-publishing post', function () {
    Bus::fake();

    $post = Post::factory()->create(['status' => PostStatus::Publishing, 'scheduled_at' => now()->subMinute()]);
    PostTarget::factory()->for($post)->create();

    $this->artisan('posts:dispatch-due')->assertExitCode(0);

    Bus::assertNotDispatched(PublishPostTarget::class);
});
