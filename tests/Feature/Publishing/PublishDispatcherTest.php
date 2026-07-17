<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PublishDispatcher;
use Illuminate\Support\Facades\Bus;

test('dispatcher fans out one job per non-terminal target', function () {
    Bus::fake();

    $post = Post::factory()->create();
    $pending = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending->value]);
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published->value]);

    app(PublishDispatcher::class)->dispatchForPost($post);

    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($pending));
});

test('dispatcher marks a blocked target failed instead of dispatching it', function () {
    Bus::fake();

    $post = Post::factory()->create();
    $blocked = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => [''],
        'status' => PostTargetStatus::Pending->value,
    ]);

    app(PublishDispatcher::class)->dispatchForPost($post);

    Bus::assertNotDispatched(PublishPostTarget::class);
    $blocked->refresh();
    expect($blocked->status)->toBe(PostTargetStatus::Failed)
        ->and($blocked->error_kind)->toBe(ErrorKind::Validation)
        ->and($blocked->error_message)->not->toBeNull();
    expect($post->refresh()->status)->toBe(PostStatus::Failed);
});

test('dispatcher publishes valid targets and fails only the blocked ones', function () {
    Bus::fake();

    $post = Post::factory()->create();
    $valid = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello world'],
        'status' => PostTargetStatus::Pending->value,
    ]);
    $igAccount = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $blocked = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $igAccount->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['a caption but no media'],
        'status' => PostTargetStatus::Pending->value,
    ]);

    app(PublishDispatcher::class)->dispatchForPost($post);

    Bus::assertDispatchedTimes(PublishPostTarget::class, 1);
    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($valid));
    expect($blocked->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($blocked->error_kind)->toBe(ErrorKind::Validation);
});
