<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
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

test('blockingTargets flags a target with no text and no media', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => [''],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['empty']);
});

test('blockingTargets flags a target whose sections are only whitespace', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['   ', "\n"],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toBe(['empty']);
});

test('blockingTargets flags an Instagram target with text but no media', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => '@insta']);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['Test'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['platform'])->toBe('instagram')
        ->and($blocked[0]['issues'])->toBe(['media_required']);
});

test('blockingTargets passes an Instagram target with text and media', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Instagram->value,
        'sections' => ['Test'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a text-only target on a platform that does not require media', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['Test'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a media-only target with no text', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => [''],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags a post mixing a video with an image', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('mixed_video_and_images');
});

test('blockingTargets flags a video longer than the platform allows', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create([
        'duration_seconds' => Platform::X->maxVideoDurationSeconds() + 10,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('video_too_long');
});

test('blockingTargets flags a video larger than the platform allows', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->video()->create([
        'size_bytes' => Platform::Bluesky->maxVideoBytes() + 1,
    ]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('video_too_large');
});

test('blockingTargets flags a GIF mixed with another image on X', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/gif']);
    PostMedia::factory()->for($post)->create(['mime' => 'image/jpeg']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('gif_not_mixable');
});

test('blockingTargets allows a single GIF on X', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/gif']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags a non-JPEG image on Instagram', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/png']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('media_wrong_format');
});

test('blockingTargets passes a JPEG image on Instagram', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/jpeg']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets passes a PNG image on X (compressed at publish)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/png']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets allows a GIF mixed with an image on LinkedIn', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/gif']);
    PostMedia::factory()->for($post)->create(['mime' => 'image/jpeg']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});
