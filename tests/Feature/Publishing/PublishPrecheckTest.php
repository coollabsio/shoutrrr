<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\SegmentMediaResolver;

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

test('blockingTargets enforces Google Business Profile event and offer options', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::GoogleBusinessProfile]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::GoogleBusinessProfile->value,
        'sections' => ['An event summary'],
        'provider_options' => ['google_business_profile' => [
            'local_post_type' => 'event',
            'title' => '',
            'start_at' => '2026-08-11T10:00:00Z',
            'end_at' => '2026-08-11T09:00:00Z',
        ]],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked[0]['issues'])->toContain('gbp_event_title_required', 'gbp_event_schedule_required');
});

test('blockingTargets enforces Google Business Profile policy safeguards', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::GoogleBusinessProfile]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::GoogleBusinessProfile->value,
        'sections' => ['Hotel deal today! Hotel deal today! Cannabis discount. Call 555-111-2222 or 555-333-4444. Visit http://127.0.0.1.'],
        'provider_options' => ['google_business_profile' => [
            'local_post_type' => 'standard',
            'cta_url' => 'http://example.test',
        ]],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked[0]['issues'])->toContain(
        'gbp_cta_url_invalid',
        'gbp_unsafe_url',
        'gbp_phone_stuffing',
        'gbp_regulated_promotion',
        'gbp_hotel_promotion',
        'gbp_repetitive_content',
    );
});

test('blockingTargets treats blank Google Business Profile CTA URLs as absent', function (string $ctaUrl) {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::GoogleBusinessProfile]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::GoogleBusinessProfile->value,
        'sections' => ['A valid local post summary'],
        'provider_options' => ['google_business_profile' => [
            'local_post_type' => 'standard',
            'cta_url' => $ctaUrl,
        ]],
    ]);

    expect(app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media'])))->toBe([]);
})->with(['empty' => '', 'whitespace' => '   ']);

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

test('blockingTargets allows a video and an image mixed across different thread segments on X', function () {
    $post = Post::factory()->create();
    $image = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $video = PostMedia::factory()->for($post)->video()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first', 'second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $video->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $image->id,
        'segment_ref' => 'break-1',
        'position' => 0,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets still flags a video and an image mixed within the same thread segment on X', function () {
    $post = Post::factory()->create();
    $image = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $video = PostMedia::factory()->for($post)->video()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first', 'second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $video->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $image->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 1,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('mixed_video_and_images');
});

test('blockingTargets allows a post mixing a video with an image on Instagram (real mixed carousel)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets allows a post mixing a video with an image on Threads (real mixed carousel)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Threads->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets allows a post mixing a video with an image on Discord (attaches every file)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Discord]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Discord->value,
        'sections' => ['hello'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags a post mixing a video with an image on LinkedIn', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->video()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn->value,
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

test('blockingTargets passes a non-JPEG image on Instagram (converted at publish)', function () {
    $post = Post::factory()->create();
    PostMedia::factory()->for($post)->create(['mime' => 'image/png']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['caption'],
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));

    expect($blocked)->toBe([]);
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

test('blockingTargets allows 4 images per section across two thread sections on X', function () {
    $post = Post::factory()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first', 'second'],
        'segment_breaks' => ['break-1'],
        'section_sources' => [0, 1],
    ]);

    foreach (range(0, 3) as $position) {
        $media = PostMedia::factory()->for($post)->create(['kind' => 'image']);
        PostMediaPlacement::factory()->create([
            'post_target_id' => $target->id,
            'post_media_id' => $media->id,
            'segment_ref' => SegmentMediaResolver::HEAD,
            'position' => $position,
        ]);
    }

    foreach (range(0, 3) as $position) {
        $media = PostMedia::factory()->for($post)->create(['kind' => 'image']);
        PostMediaPlacement::factory()->create([
            'post_target_id' => $target->id,
            'post_media_id' => $media->id,
            'segment_ref' => 'break-1',
            'position' => $position,
        ]);
    }

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toBe([]);
});

test('blockingTargets flags 5 images placed on a single thread section as too_many_media', function () {
    $post = Post::factory()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['first'],
    ]);

    foreach (range(0, 4) as $position) {
        $media = PostMedia::factory()->for($post)->create(['kind' => 'image']);
        PostMediaPlacement::factory()->create([
            'post_target_id' => $target->id,
            'post_media_id' => $media->id,
            'segment_ref' => SegmentMediaResolver::HEAD,
            'position' => $position,
        ]);
    }

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('too_many_media');
});

test('blockingTargets flags attached media with no placement as unplaced_media', function () {
    $post = Post::factory()->create();
    $placed = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $placed->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

    expect($blocked)->toHaveCount(1)
        ->and($blocked[0]['issues'])->toContain('unplaced_media');
});

test('blockingTargets passes a target whose placements cover all attached media', function () {
    $post = Post::factory()->create();
    $first = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $second = PostMedia::factory()->for($post)->create(['kind' => 'image']);
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'sections' => ['hello'],
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $first->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 0,
    ]);
    PostMediaPlacement::factory()->create([
        'post_target_id' => $target->id,
        'post_media_id' => $second->id,
        'segment_ref' => SegmentMediaResolver::HEAD,
        'position' => 1,
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'targets.placements', 'media']));

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
