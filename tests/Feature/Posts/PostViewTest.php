<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostView;
use Illuminate\Support\Facades\Context;

test('it serializes a post with targets, media, and advisory issues', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    Context::add('workspace_id', $workspace->id);

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $user->id, 'base_text' => 'hi']);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value, 'handle' => '@ada']);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'sections' => [str_repeat('a', 400)], // over X's 280 -> advisory issue
    ]);
    PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $post->id, 'position' => 0]);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view)->toHaveKeys(['id', 'base_text', 'status', 'updated_at', 'destination', 'targets', 'media'])
        ->and($view['targets'][0]['handle'])->toBe('@ada')
        ->and($view['targets'][0]['issues'])->toContain('section_too_long')
        ->and($view['media'])->toHaveCount(1)
        ->and($view)->not->toHaveKey('secret');
});
