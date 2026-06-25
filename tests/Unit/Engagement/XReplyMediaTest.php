<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\XEngagementConnector;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function xMediaConnector(): XEngagementConnector
{
    return new XEngagementConnector(app(Factory::class));
}

beforeEach(fn () => Storage::fake('public'));

test('postReply uploads images and attaches media_ids to the tweet', function () {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '111']]),
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '999']]),
    ]);

    $path = Storage::disk('public')->putFileAs('media/ws', UploadedFile::fake()->image('x.jpg'), 'x.jpg');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'image', 'mime' => 'image/jpeg']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'handle' => 'me']);
    $parent = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '900']);

    $result = xMediaConnector()->postReply($account, $parent, 'hi', ['access_token' => 't'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('999');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/2/tweets')
        && ($r['media']['media_ids'][0] ?? null) === '111');
});

test('postReply uploads video via chunked init/append/finalize/STATUS and attaches media_id', function () {
    Http::fake([
        'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '222']]),
        'api.x.com/2/media/upload/222/append' => Http::response(null, 204),
        'api.x.com/2/media/upload/222/finalize' => Http::response(['data' => ['id' => '222']]),
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]),
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '888']]),
    ]);

    $path = Storage::disk('public')->putFileAs('media/ws', UploadedFile::fake()->create('v.mp4', 100, 'video/mp4'), 'v.mp4');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'video', 'mime' => 'video/mp4']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'handle' => 'me']);
    $parent = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '901']);

    $result = xMediaConnector()->postReply($account, $parent, 'video reply', ['access_token' => 't'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('888');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/2/tweets')
        && ($r['media']['media_ids'][0] ?? null) === '222');
});

test('postReply tolerates an in_progress STATUS and polls until succeeded', function () {
    $statusCalls = 0;

    // A closure stub in Http::fake intercepts every matching request, so route by
    // URL: init/append/finalize get their normal responses, and the STATUS poll
    // returns in_progress once (check_after_secs:1 keeps the single sleep short)
    // then succeeded — proving the loop waits instead of giving up.
    Http::fake([
        'api.x.com/2/media/upload*' => function ($request) use (&$statusCalls) {
            $url = $request->url();

            if (str_contains($url, '/initialize')) {
                return Http::response(['data' => ['id' => '333']]);
            }
            if (str_contains($url, '/append')) {
                return Http::response(null, 204);
            }
            if (str_contains($url, '/finalize')) {
                return Http::response(['data' => ['id' => '333']]);
            }

            // STATUS poll.
            $statusCalls++;

            return Http::response($statusCalls === 1
                ? ['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 1]]]
                : ['data' => ['processing_info' => ['state' => 'succeeded']]]);
        },
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '777']]),
    ]);

    $path = Storage::disk('public')->putFileAs('media/ws', UploadedFile::fake()->create('v2.mp4', 100, 'video/mp4'), 'v2.mp4');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'video', 'mime' => 'video/mp4']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'handle' => 'me']);
    $parent = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '902']);

    $result = xMediaConnector()->postReply($account, $parent, 'video reply', ['access_token' => 't'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('777');
    // Proves the loop did not give up on the first in_progress response: it
    // polled twice (once in_progress, then succeeded).
    expect($statusCalls)->toBe(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/2/tweets')
        && ($r['media']['media_ids'][0] ?? null) === '333');
});
