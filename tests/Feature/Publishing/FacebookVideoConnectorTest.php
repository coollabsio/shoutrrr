<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\FacebookConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

/**
 * @param  array<string, mixed>  $targetOverrides
 */
function fbVideoContext(PostMedia $media, array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge(['platform' => Platform::Facebook->value], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    return new PublishContext(
        target: $target,
        segments: ['video post'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );
}

/**
 * Collect the multipart "name" => "contents" pairs from a request for readable assertions.
 *
 * @return array<string, mixed>
 */
function multipartFields(Request $request): array
{
    return collect($request->data())
        ->filter(fn ($part) => is_array($part) && array_key_exists('name', $part))
        ->pluck('contents', 'name')
        ->all();
}

test('facebook video publish walks start, transfer (looped), finish and returns the video id', function () {
    $bytes = str_repeat('x', 30);
    Storage::disk('public')->put('media/clip.mp4', $bytes);

    $media = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'mime' => 'video/mp4',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/page123/videos' => Http::sequence()
            ->push(['upload_session_id' => 'sess-1', 'video_id' => 'vid-1', 'start_offset' => 0, 'end_offset' => 20])
            ->push(['start_offset' => 20, 'end_offset' => 30])
            ->push(['start_offset' => 30, 'end_offset' => 30])
            ->push(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbVideoContext($media));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['vid-1']);

    Http::assertSentCount(4);

    $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();

    // Phase 1: start.
    expect($requests[0]['upload_phase'])->toBe('start')
        ->and($requests[0]['file_size'])->toBe(30);

    // Phase 2: transfer covering bytes [0, 20).
    $transfer1 = multipartFields($requests[1]);
    expect($transfer1['upload_phase'])->toBe('transfer')
        ->and($transfer1['upload_session_id'])->toBe('sess-1')
        ->and($transfer1['start_offset'])->toBe('0')
        ->and($transfer1['video_file_chunk'])->toBe(substr($bytes, 0, 20));

    // Phase 3: transfer covering the remaining bytes [20, 30).
    $transfer2 = multipartFields($requests[2]);
    expect($transfer2['upload_phase'])->toBe('transfer')
        ->and($transfer2['start_offset'])->toBe('20')
        ->and($transfer2['video_file_chunk'])->toBe(substr($bytes, 20, 10));

    // Phase 4: finish.
    expect($requests[3]['upload_phase'])->toBe('finish')
        ->and($requests[3]['upload_session_id'])->toBe('sess-1')
        ->and($requests[3]['description'])->toBe('video post');
});

test('facebook video resume skips the start phase when a session is already persisted', function () {
    $bytes = str_repeat('x', 30);
    Storage::disk('public')->put('media/clip.mp4', $bytes);

    $media = PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'mime' => 'video/mp4',
    ]);

    $context = fbVideoContext($media, [
        'media_upload_state' => [
            $media->id => [
                'remote_ref' => 'vid-1',
                'state' => 'processing',
                'blob' => ['upload_session_id' => 'sess-1', 'start_offset' => 20, 'end_offset' => 30],
            ],
        ],
    ]);

    Http::fake([
        'https://graph.facebook.com/*/page123/videos' => Http::sequence()
            ->push(['start_offset' => 30, 'end_offset' => 30])
            ->push(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['vid-1']);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => is_array($request->data()) && ($request['upload_phase'] ?? null) === 'start');

    $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();

    $transfer = multipartFields($requests[0]);
    expect($transfer['upload_phase'])->toBe('transfer')
        ->and($transfer['upload_session_id'])->toBe('sess-1')
        ->and($transfer['start_offset'])->toBe('20')
        ->and($transfer['video_file_chunk'])->toBe(substr($bytes, 20, 10));

    expect($requests[1]['upload_phase'])->toBe('finish');
});
