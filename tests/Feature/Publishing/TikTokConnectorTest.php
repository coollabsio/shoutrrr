<?php

use App\Dto\Post\TikTokPostOptions;
use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\TikTokConnector;
use App\Services\Publishing\PublishConnectorRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

const TIKTOK_PUBLISH = 'https://open.tiktokapis.com/v2/post/publish';

beforeEach(function () {
    Storage::fake('public');
});

/**
 * @param  list<PostMedia>  $media
 * @param  list<string>  $segments
 */
function tikTokContext(array $media, ?TikTokPostOptions $options = null, array $segments = ['New drop is live'], ?array $uploadState = null): PublishContext
{
    $target = PostTarget::factory()->create([
        'platform' => Platform::TikTok->value,
        'tiktok_options' => $options ?? publicTikTokOptions(),
        'media_upload_state' => $uploadState,
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok->value,
        'remote_account_id' => 'open-123',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['access_token' => 'tiktok-access'],
    );
}

function publicTikTokOptions(array $overrides = []): TikTokPostOptions
{
    return TikTokPostOptions::fromArray([
        'post_mode' => 'direct',
        'privacy_level' => 'PUBLIC_TO_EVERYONE',
        'allow_comment' => true,
        'allow_duet' => false,
        'allow_stitch' => true,
        'disclose_commercial' => true,
        'brand_organic' => false,
        'branded_content' => true,
        ...$overrides,
    ]);
}

function tikTokVideo(int $seconds = 30): PostMedia
{
    return PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4', 'duration_seconds' => $seconds]);
}

function tikTokPhoto(string $name): PostMedia
{
    return PostMedia::factory()->create(['disk' => 'public', 'path' => "media/{$name}.jpg", 'mime' => 'image/jpeg']);
}

/**
 * @return array<string, mixed>
 */
function tikTokCreator(array $data = []): array
{
    return [
        'data' => [
            'creator_username' => 'harbor.studio',
            'creator_nickname' => 'Harbor Studio',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 600,
            ...$data,
        ],
        'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-c'],
    ];
}

/**
 * @return array<string, mixed>
 */
function tikTokOk(array $data): array
{
    return ['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-ok']];
}

function publishIdOf(PublishContext $context): ?string
{
    return new MediaUploadState($context->target->fresh()->media_upload_state)->remoteRef('tiktok-publish');
}

test('the registry hands out the tiktok connector', function () {
    expect(app(PublishConnectorRegistry::class)->for(Platform::TikTok))->toBeInstanceOf(TikTokConnector::class);
});

test('a direct video post sends the caption, privacy, interactions and disclosure then waits for TikTok', function () {
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator()),
        TIKTOK_PUBLISH.'/video/init/' => Http::response(tikTokOk(['publish_id' => 'v_pub_1'])),
    ]);
    $context = tikTokContext([tikTokVideo()]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->retryAfter)->toBe(10)
        ->and(publishIdOf($context))->toBe('v_pub_1');

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_PUBLISH.'/video/init/'
        && $request->hasHeader('Authorization', 'Bearer tiktok-access')
        && $request['post_info'] === [
            'title' => 'New drop is live',
            'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'disable_comment' => false,
            'disable_duet' => true,
            'disable_stitch' => false,
            'brand_organic_toggle' => false,
            'brand_content_toggle' => true,
        ]
        && $request['source_info']['source'] === 'PULL_FROM_URL'
        && str_ends_with((string) $request['source_info']['video_url'], '/storage/media/clip.mp4'));
});

test('an inbox video carries only the media', function () {
    Http::fake([TIKTOK_PUBLISH.'/inbox/video/init/' => Http::response(tikTokOk(['publish_id' => 'v_inbox_1']))]);
    $context = tikTokContext([tikTokVideo()], new TikTokPostOptions(mode: TikTokPostMode::Inbox));

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and(publishIdOf($context))->toBe('v_inbox_1');

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_PUBLISH.'/inbox/video/init/'
        && array_keys($request->data()) === ['source_info']);
    // An inbox draft needs no creator check: the creator finishes it in TikTok.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'creator_info'));
});

test('a direct photo post sends every photo with the first as the cover', function () {
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator()),
        TIKTOK_PUBLISH.'/content/init/' => Http::response(tikTokOk(['publish_id' => 'p_pub_1'])),
    ]);
    $context = tikTokContext([tikTokPhoto('one'), tikTokPhoto('two')]);

    app(TikTokConnector::class)->publish($context);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== TIKTOK_PUBLISH.'/content/init/') {
            return false;
        }

        $images = $request['source_info']['photo_images'];

        return $request['media_type'] === 'PHOTO'
            && $request['post_mode'] === 'DIRECT_POST'
            && $request['post_info'] === [
                'description' => 'New drop is live',
                'privacy_level' => 'PUBLIC_TO_EVERYONE',
                'disable_comment' => false,
                'brand_organic_toggle' => false,
                'brand_content_toggle' => true,
            ]
            && count($images) === 2
            && str_ends_with($images[0], '/storage/media/one.jpg')
            && str_ends_with($images[1], '/storage/media/two.jpg')
            && $request['source_info']['photo_cover_index'] === 0;
    });
});

test('photos sent to the inbox keep the caption but no direct post choices', function () {
    Http::fake([TIKTOK_PUBLISH.'/content/init/' => Http::response(tikTokOk(['publish_id' => 'p_inbox_1']))]);
    $context = tikTokContext([tikTokPhoto('one')], new TikTokPostOptions(mode: TikTokPostMode::Inbox));

    app(TikTokConnector::class)->publish($context);

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_PUBLISH.'/content/init/'
        && $request['post_mode'] === 'MEDIA_UPLOAD'
        && $request['post_info'] === ['description' => 'New drop is live']);
});

test('a photo larger than TikTok accepts is sent as a downscaled rendition', function () {
    Storage::disk('public')->put('media/huge.jpg', transparentPng(2400, 1600));
    $photo = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/huge.jpg',
        'mime' => 'image/jpeg',
        'width' => 2400,
        'height' => 1600,
    ]);
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator()),
        TIKTOK_PUBLISH.'/content/init/' => Http::response(tikTokOk(['publish_id' => 'p_pub_2'])),
    ]);

    app(TikTokConnector::class)->publish(tikTokContext([$photo]));

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_PUBLISH.'/content/init/'
        && ! str_ends_with($request['source_info']['photo_images'][0], '/media/huge.jpg')
        && str_contains($request['source_info']['photo_images'][0], 'fit1920x1080'));
});

test('a later run reports the post published once TikTok has finished', function (string $status) {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk(['status' => $status]))]);
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing']]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['v_pub_1']);
    Http::assertSent(fn (Request $request): bool => $request['publish_id'] === 'v_pub_1');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/init/'));
})->with(['PUBLISH_COMPLETE', 'SEND_TO_USER_INBOX']);

test('a public post is stored under its TikTok post id so it can be linked', function () {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk([
        'status' => 'PUBLISH_COMPLETE',
        'publicaly_available_post_id' => [7710962537105247510],
    ]))]);
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing']]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['7710962537105247510']);
});

test('a later run keeps waiting while TikTok is still processing', function (string $status) {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk(['status' => $status]))]);
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing']]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and(publishIdOf($context))->toBe('v_pub_1');
})->with(['PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD']);

test('a status TikTok has not documented keeps the publish id and keeps waiting', function (string $status) {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk(['status' => $status]))]);
    Log::spy();
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing']]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and(publishIdOf($context))->toBe('v_pub_1');
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'unknown publish status')
            && $context['status'] === $status)
        ->once();
})->with(['a new status' => 'PROCESSING_MODERATION', 'no status' => '']);

test('a publish id TikTok no longer recognizes is forgotten and fails without an automatic retry', function () {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response([
        'error' => ['code' => 'invalid_publish_id', 'message' => 'The publish_id does not exist', 'log_id' => 'log-s'],
    ], 400)]);
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing'], '_polls' => 20]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorKind->isRetryable())->toBeFalse()
        ->and($result->errorMessage)->toContain('Check the TikTok profile and inbox before retrying')
        ->and(publishIdOf($context))->toBeNull()
        ->and(new MediaUploadState($context->target->fresh()->media_upload_state)->polls())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/init/'));
});

test('a status check that fails for a passing reason keeps the publish id', function (int $status, string $code, ErrorKind $kind) {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response([
        'error' => ['code' => $code, 'message' => 'failed', 'log_id' => 'log-s'],
    ], $status)]);
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing']]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe($kind)
        ->and(publishIdOf($context))->toBe('v_pub_1');
})->with([
    'rate limited' => [429, 'rate_limit_exceeded', ErrorKind::RateLimited],
    'expired token' => [401, 'access_token_invalid', ErrorKind::AuthExpired],
    'tiktok outage' => [500, 'internal_error', ErrorKind::ServerError],
    'token not authorized for the id' => [400, 'token_not_authorized_for_specified_publish_id', ErrorKind::Validation],
]);

test('status polls slow down the longer TikTok takes', function (int $pollsSoFar, int $delay) {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk(['status' => 'PROCESSING_DOWNLOAD']))]);
    $context = tikTokContext([tikTokVideo()], uploadState: [
        'tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing'],
        '_polls' => $pollsSoFar,
    ]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->retryAfter)->toBe($delay);
})->with([
    'first poll' => [1, 10],
    'first minute' => [5, 10],
    'after a minute' => [6, 30],
    'a few minutes in' => [14, 30],
    'after five minutes' => [15, 60],
    'last poll' => [39, 60],
]);

test('the poll budget gives TikTok about half an hour to process a post', function () {
    Http::fake([
        TIKTOK_PUBLISH.'/inbox/video/init/' => Http::response(tikTokOk(['publish_id' => 'v_pub_1'])),
        TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk(['status' => 'PROCESSING_DOWNLOAD'])),
    ]);
    $context = tikTokContext([tikTokVideo()], new TikTokPostOptions(mode: TikTokPostMode::Inbox));
    $connector = app(TikTokConnector::class);
    $window = 0;

    // The publish job allows 40 polls; it bumps the counter after each run.
    for ($polls = 0; $polls < 40; $polls++) {
        $result = $connector->publish($context);
        $window += $result->retryAfter;
        $state = new MediaUploadState($context->target->media_upload_state);
        $state->incrementPolls();
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }

    expect($window)->toBeGreaterThanOrEqual(30 * 60)
        ->and(publishIdOf($context))->toBe('v_pub_1');
    Http::assertSentCount(40);
});

test('a failed post is reported with its reason and forgotten so a retry starts over', function (string $reason, ErrorKind $kind, string $message) {
    Http::fake([TIKTOK_PUBLISH.'/status/fetch/' => Http::response(tikTokOk(['status' => 'FAILED', 'fail_reason' => $reason]))]);
    $context = tikTokContext([tikTokVideo()], uploadState: ['tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing'], '_polls' => 20]);

    $result = app(TikTokConnector::class)->publish($context);

    // The retry uploads the post again, so it gets the whole poll budget.
    expect($result->errorKind)->toBe($kind)
        ->and($result->errorMessage)->toContain($message)
        ->and(publishIdOf($context))->toBeNull()
        ->and(new MediaUploadState($context->target->fresh()->media_upload_state)->polls())->toBe(0);
})->with([
    'pull failed' => ['video_pull_failed', ErrorKind::ServerError, 'could not download the video'],
    'internal' => ['internal', ErrorKind::ServerError, 'internal error'],
    'auth removed' => ['auth_removed', ErrorKind::AuthExpired, 'Reconnect the TikTok account'],
    'frame rate' => ['frame_rate_check_failed', ErrorKind::Validation, '23 and 60 frames per second'],
    'spam' => ['spam_risk_too_many_posts', ErrorKind::Validation, 'daily posting limit'],
    'undocumented' => ['something_new', ErrorKind::Validation, '(something_new)'],
]);

test('a direct post fails instead of changing a choice TikTok no longer allows', function (array $creator, TikTokPostOptions $options, string $message) {
    Http::fake([TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator($creator))]);
    $context = tikTokContext([tikTokVideo(120)], $options);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain($message);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/init/'));
})->with([
    'no privacy level chosen' => [[], new TikTokPostOptions, 'Choose who can view'],
    'privacy level withdrawn' => [['privacy_level_options' => ['SELF_ONLY']], new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::PublicToEveryone), 'no longer offered'],
    'comments turned off' => [['comment_disabled' => true], new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::SelfOnly, allowComment: true), 'turned comments off'],
    'duets turned off' => [['duet_disabled' => true], new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::SelfOnly, allowDuet: true), 'turned duets off'],
    'stitches turned off' => [['stitch_disabled' => true], new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::SelfOnly, allowStitch: true), 'turned stitches off'],
    'video too long' => [['max_video_post_duration_sec' => 60], new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::SelfOnly), '60-second limit'],
]);

test('a zero maximum duration from TikTok does not block a video', function () {
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator(['max_video_post_duration_sec' => 0])),
        TIKTOK_PUBLISH.'/video/init/' => Http::response(tikTokOk(['publish_id' => 'v_pub_3'])),
    ]);

    $result = app(TikTokConnector::class)->publish(tikTokContext([tikTokVideo(500)], new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::SelfOnly)));

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing);
});

test('duet and stitch choices are not checked for a photo post', function () {
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator(['duet_disabled' => true, 'stitch_disabled' => true])),
        TIKTOK_PUBLISH.'/content/init/' => Http::response(tikTokOk(['publish_id' => 'p_pub_3'])),
    ]);

    $result = app(TikTokConnector::class)->publish(tikTokContext(
        [tikTokPhoto('one')],
        new TikTokPostOptions(privacyLevel: TikTokPrivacyLevel::SelfOnly, allowDuet: true, allowStitch: true),
    ));

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing);
});

test('tiktok api errors map to the publishing error kinds', function (int $status, string $code, ErrorKind $kind) {
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator()),
        TIKTOK_PUBLISH.'/video/init/' => Http::response(['data' => [], 'error' => ['code' => $code, 'message' => 'nope', 'log_id' => 'log-e']], $status),
    ]);
    $context = tikTokContext([tikTokVideo()]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe($kind)
        ->and($result->httpStatus)->toBe($status)
        ->and($result->errorMessage)->not->toContain('tiktok-access')
        ->and(publishIdOf($context))->toBeNull();
})->with([
    'rate limited' => [429, 'rate_limit_exceeded', ErrorKind::RateLimited],
    'token rejected' => [401, 'access_token_invalid', ErrorKind::AuthExpired],
    'scope missing' => [401, 'scope_not_authorized', ErrorKind::AuthExpired],
    'server error' => [500, 'internal_error', ErrorKind::ServerError],
    'unverified media url' => [403, 'url_ownership_unverified', ErrorKind::Validation],
    'unaudited app' => [403, 'unaudited_client_can_only_post_to_private_accounts', ErrorKind::Validation],
    'daily limit, http 200' => [200, 'spam_risk_too_many_posts', ErrorKind::Validation],
]);

test('a creator check TikTok refuses fails the post with its reason', function () {
    Http::fake([TIKTOK_PUBLISH.'/creator_info/query/' => Http::response([
        'data' => [],
        'error' => ['code' => 'spam_risk_user_banned_from_posting', 'message' => '', 'log_id' => 'log-b'],
    ])]);

    $result = app(TikTokConnector::class)->publish(tikTokContext([tikTokVideo()]));

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toBe('TikTok is not letting this account post right now. Try again later.');
});

test('an unreachable TikTok is a network failure', function () {
    Http::fake([TIKTOK_PUBLISH.'/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $result = app(TikTokConnector::class)->publish(tikTokContext([tikTokVideo()]));

    expect($result->errorKind)->toBe(ErrorKind::Network);
});

test('an init response without a publish id fails the post', function () {
    Http::fake([
        TIKTOK_PUBLISH.'/creator_info/query/' => Http::response(tikTokCreator()),
        TIKTOK_PUBLISH.'/video/init/' => Http::response(tikTokOk([])),
    ]);
    $context = tikTokContext([tikTokVideo()]);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorMessage)->toContain('publish id')
        ->and(publishIdOf($context))->toBeNull();
});

test('a tiktok post needs media in a shape TikTok accepts', function (Closure $media, string $message) {
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tikTokContext($media()));

    expect($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toBe($message);
    Http::assertNothingSent();
})->with([
    'no media' => [fn (): array => [], 'TikTok needs a video or at least one photo.'],
    'two videos' => [fn (): array => [tikTokVideo(), tikTokVideo()], 'A TikTok post is one video or up to 35 photos.'],
    'video and photo' => [fn (): array => [tikTokVideo(), tikTokPhoto('one')], 'A TikTok post is one video or up to 35 photos.'],
    'too many photos' => [fn (): array => array_map(fn (int $i): PostMedia => tikTokPhoto("p{$i}"), range(1, 36)), 'A TikTok post is one video or up to 35 photos.'],
]);

test('a target that already published is not sent again', function () {
    Http::fake();
    $context = tikTokContext([tikTokVideo()]);
    $context->target->forceFill(['remote_id' => 'v_pub_done', 'remote_ids' => ['v_pub_done']])->save();

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['v_pub_done']);
    Http::assertNothingSent();
});

test('a missing access token asks for a reconnect without calling TikTok', function () {
    Http::fake();
    $context = tikTokContext([tikTokVideo()]);
    $context = new PublishContext($context->target, $context->segments, $context->media, $context->account, []);

    $result = app(TikTokConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired);
    Http::assertNothingSent();
});

test('a tiktok post cannot be deleted through the api', function () {
    $context = tikTokContext([tikTokVideo()]);

    expect(fn () => app(TikTokConnector::class)->delete($context->target, ['access_token' => 'tiktok-access']))
        ->toThrow(RuntimeException::class, 'TikTok has no API for deleting posts');
});
