<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;

test('blockingTargets flags an over-limit Bluesky target', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'handle' => '@bsky']);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky->value,
        'sections' => [str_repeat('x', 400)],
        'auto_split' => false,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['platform'])->toBe('bluesky')
        ->and($blocked[0]['issues'])->toContain('section_too_long')
        ->and($blocked[0]['handle'])->toBe('@bsky');
});

test('blockingTargets passes a within-limit target', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello world'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});
