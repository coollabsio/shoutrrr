<?php

use App\Dto\Post\TikTokPostOptions;
use App\Dto\Publishing\MediaUploadState;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\TikTokPostMode;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const TIKTOK_STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';

beforeEach(function () {
    Storage::fake('public');
    Bus::fake();
});

function pollingTikTokTarget(int $pollsSoFar): PostTarget
{
    $post = Post::factory()->create(['status' => PostStatus::Publishing]);
    PostMedia::factory()->video()->create(['post_id' => $post->id, 'disk' => 'public', 'path' => 'media/clip.mp4']);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tiktok-access']);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::TikTok->value,
        'status' => PostTargetStatus::Publishing->value,
        'tiktok_options' => new TikTokPostOptions(mode: TikTokPostMode::Inbox),
        'media_upload_state' => [
            'tiktok-publish' => ['remote_ref' => 'v_pub_1', 'state' => 'processing'],
            '_polls' => $pollsSoFar,
        ],
    ]);
}

function runPublishJob(PostTarget $target): void
{
    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );
}

test('running out of polls keeps the tiktok publish id so a retry resumes the same post', function () {
    Http::fake([TIKTOK_STATUS_URL => Http::sequence()
        ->push(['data' => ['status' => 'PROCESSING_DOWNLOAD'], 'error' => ['code' => 'ok']])
        ->push(['data' => ['status' => 'PUBLISH_COMPLETE'], 'error' => ['code' => 'ok']]),
    ]);
    $target = pollingTikTokTarget(pollsSoFar: 40);

    runPublishJob($target);

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and(new MediaUploadState($target->media_upload_state)->remoteRef('tiktok-publish'))->toBe('v_pub_1');

    // What the retry button does before dispatching the job again.
    $target->forceFill(['status' => PostTargetStatus::Pending->value, 'error_kind' => null, 'error_message' => null])->save();
    runPublishJob($target);

    expect($target->refresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('v_pub_1');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/init/'));
});
