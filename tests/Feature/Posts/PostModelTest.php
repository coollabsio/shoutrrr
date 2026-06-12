<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use Illuminate\Support\Facades\Context;

test('a post casts its status enum and exposes its targets', function () {
    $workspace = Workspace::factory()->create();
    Context::add('workspace_id', $workspace->id);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Draft->value,
    ]);

    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
    ]);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->targets)->toHaveCount(1)
        ->and($post->targets->first()->platform)->toBe(Platform::X);
});

test('posts are workspace scoped', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    Post::factory()->create(['workspace_id' => $a->id]);
    Post::factory()->create(['workspace_id' => $b->id]);

    Context::add('workspace_id', $a->id);

    expect(Post::query()->count())->toBe(1)
        ->and(Post::withoutGlobalScopes()->count())->toBe(2);
});
