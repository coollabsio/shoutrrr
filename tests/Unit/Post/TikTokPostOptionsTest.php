<?php

use App\Dto\Post\TikTokPostOptions;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;

test('the defaults follow the TikTok content sharing guidelines', function () {
    expect((new TikTokPostOptions)->toArray())->toBe([
        'post_mode' => 'direct',
        'privacy_level' => null,
        'allow_comment' => false,
        'allow_duet' => false,
        'allow_stitch' => false,
        'disclose_commercial' => false,
        'brand_organic' => false,
        'branded_content' => false,
    ]);
});

test('options round-trip through their stored shape', function () {
    $options = new TikTokPostOptions(
        mode: TikTokPostMode::Inbox,
        privacyLevel: TikTokPrivacyLevel::MutualFollowFriends,
        allowComment: true,
        discloseCommercial: true,
        brandedContent: true,
    );

    expect(TikTokPostOptions::fromArray($options->toArray()))->toEqual($options);
});

test('an unreadable stored value falls back to the unset defaults', function () {
    $options = TikTokPostOptions::fromArray(['post_mode' => 'publish', 'privacy_level' => 'EVERYONE', 'allow_comment' => '0']);

    expect($options->mode)->toBe(TikTokPostMode::Direct)
        ->and($options->privacyLevel)->toBeNull()
        ->and($options->allowComment)->toBeFalse();
    expect(TikTokPostOptions::fromArray(null))->toEqual(new TikTokPostOptions);
});

test('a disclosure is incomplete until a promotion type is chosen', function () {
    expect(new TikTokPostOptions(discloseCommercial: true)->hasIncompleteDisclosure())->toBeTrue()
        ->and(new TikTokPostOptions(discloseCommercial: true, brandOrganic: true)->hasIncompleteDisclosure())->toBeFalse()
        ->and((new TikTokPostOptions)->hasIncompleteDisclosure())->toBeFalse();
});
