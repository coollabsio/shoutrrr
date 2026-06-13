<?php

use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
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
