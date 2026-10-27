<?php

use App\Enums\PostStatus;
use App\Jobs\TriggerKlipyShare;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\AddPostVideoTool;
use App\Mcp\Tools\AttachGifTool;
use App\Mcp\Tools\DuplicatePostTool;
use App\Mcp\Tools\GetNextSlotTool;
use App\Mcp\Tools\SearchGifsTool;
use App\Mcp\Tools\UpdateMediaAltTool;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\PostMedia;
use App\Models\Workspace;
use App\Support\SafeVideoFetcher;
use App\Support\VideoProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<array{0: int, 1: int}>  $slots  [weekday, hour] pairs
 */
function composerToolSchedule(Workspace $workspace, array $slots): void
{
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    foreach ($slots as $i => [$weekday, $hour]) {
        PostingScheduleSlot::factory()->create([
            'posting_schedule_id' => $schedule->id,
            'weekday' => $weekday,
            'hour' => $hour,
            'minute' => 0,
            'position' => $i,
        ]);
    }
}

test('get_next_slot lists open slots and skips occupied ones', function (): void {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00'); // Monday
    [$user, $workspace] = mcpOwner();
    composerToolSchedule($workspace, [[1, 9], [1, 11]]);
    Post::factory()->for($workspace)->create([
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-05-18T09:00:00+00:00',
    ]);

    ShoutrrrServer::actingAs($user)->tool(GetNextSlotTool::class)
        ->assertOk()
        ->assertSee(['"has_schedule": true', '"slot": "2026-05-18T11:00:00+00:00"', '"timezone": "UTC"']);
});

test('get_next_slot reports when no schedule exists', function (): void {
    [$user] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(GetNextSlotTool::class)
        ->assertOk()
        ->assertSee(['"has_schedule": false', '"slot": null']);
});

test('duplicate_post copies a published post into a new draft', function (): void {
    [$user, $workspace] = mcpOwner();
    $source = Post::factory()->for($workspace)->create([
        'status' => PostStatus::Published,
        'segments' => ['shipped it'],
        'base_text' => 'shipped it',
    ]);

    ShoutrrrServer::actingAs($user)->tool(DuplicatePostTool::class, ['post_id' => $source->id])
        ->assertOk()
        ->assertSee('"status": "draft"');

    $copy = Post::query()->where('workspace_id', $workspace->id)->whereKeyNot($source->id)->sole();
    expect($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->segments)->toBe(['shipped it']);
});

test('duplicate_post refuses a draft and posts from other workspaces', function (): void {
    [$user, $workspace] = mcpOwner();
    $draft = Post::factory()->for($workspace)->create(['status' => PostStatus::Draft]);
    $foreign = Post::factory()->for(Workspace::factory())->create(['status' => PostStatus::Published]);

    ShoutrrrServer::actingAs($user)->tool(DuplicatePostTool::class, ['post_id' => $draft->id])
        ->assertHasErrors(['Only published, partial, failed, or missed posts can be copied.']);
    ShoutrrrServer::actingAs($user)->tool(DuplicatePostTool::class, ['post_id' => $foreign->id])
        ->assertHasErrors(['No post with that id exists in this workspace.']);

    expect(Post::withoutGlobalScopes()->count())->toBe(2);
});

test('add_post_video downloads an mp4 into the workspace library', function (): void {
    Storage::fake('local');
    [$user, $workspace] = mcpOwner();

    $fetcher = Mockery::mock(SafeVideoFetcher::class);
    $fetcher->shouldReceive('fetchToFile')->once()
        ->with('https://example.com/clip.mp4', Mockery::type('string'))
        ->andReturnUsing(function (string $url, string $path): void {
            file_put_contents($path, "\x00\x00\x00\x20ftypisom".str_repeat("\x00", 64));
        });
    app()->instance(SafeVideoFetcher::class, $fetcher);

    $probe = Mockery::mock(VideoProbe::class);
    $probe->shouldReceive('probe')->once()->andReturn(['duration_seconds' => 12, 'width' => 1080, 'height' => 1920]);
    app()->instance(VideoProbe::class, $probe);

    ShoutrrrServer::actingAs($user)->tool(AddPostVideoTool::class, [
        'url' => 'https://example.com/clip.mp4',
        'alt_text' => 'a demo',
    ])->assertOk()->assertSee('"duration_seconds": 12');

    $media = PostMedia::query()->where('workspace_id', $workspace->id)->sole();
    expect($media->kind)->toBe('video')
        ->and($media->mime)->toBe('video/mp4')
        ->and($media->post_id)->toBeNull()
        ->and($media->alt_text)->toBe('a demo')
        ->and([$media->width, $media->height, $media->duration_seconds])->toBe([1080, 1920, 12]);
    Storage::disk('local')->assertExists($media->path);
});

test('add_post_video reports a rejected download', function (): void {
    [$user] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(AddPostVideoTool::class, ['url' => 'http://127.0.0.1/clip.mp4'])
        ->assertHasErrors();

    expect(PostMedia::query()->count())->toBe(0);
});

test('update_media_alt sets alt text on library and draft media', function (): void {
    [$user, $workspace] = mcpOwner();
    $library = PostMedia::factory()->for($workspace)->create(['post_id' => null, 'alt_text' => null]);
    $draft = Post::factory()->for($workspace)->create(['status' => PostStatus::Draft]);
    $attached = PostMedia::factory()->for($workspace)->create(['post_id' => $draft->id, 'alt_text' => 'old']);

    ShoutrrrServer::actingAs($user)->tool(UpdateMediaAltTool::class, ['media_id' => $library->id, 'alt_text' => 'a red bike'])
        ->assertOk();
    ShoutrrrServer::actingAs($user)->tool(UpdateMediaAltTool::class, ['media_id' => $attached->id, 'alt_text' => null])
        ->assertOk();

    expect($library->fresh()->alt_text)->toBe('a red bike')
        ->and($attached->fresh()->alt_text)->toBeNull();
});

test('update_media_alt refuses media on a published post or another workspace', function (): void {
    [$user, $workspace] = mcpOwner();
    $published = Post::factory()->for($workspace)->create(['status' => PostStatus::Published]);
    $locked = PostMedia::factory()->for($workspace)->create(['post_id' => $published->id, 'alt_text' => 'kept']);
    $foreign = PostMedia::factory()->for(Workspace::factory())->create(['alt_text' => 'theirs']);

    ShoutrrrServer::actingAs($user)->tool(UpdateMediaAltTool::class, ['media_id' => $locked->id, 'alt_text' => 'new'])
        ->assertHasErrors(['This post can no longer be edited.']);
    ShoutrrrServer::actingAs($user)->tool(UpdateMediaAltTool::class, ['media_id' => $foreign->id, 'alt_text' => 'new'])
        ->assertHasErrors(['No media with that id exists in this workspace.']);

    expect($locked->fresh()->alt_text)->toBe('kept')
        ->and($foreign->fresh()->alt_text)->toBe('theirs');
});

test('search_gifs returns normalized items', function (): void {
    config()->set('services.klipy.key', 'test-key');
    Http::fake(['https://api.klipy.com/*' => Http::response([
        'result' => true,
        'data' => [
            'has_next' => true,
            'data' => [[
                'id' => 1,
                'slug' => 'yay-1',
                'title' => 'Yay',
                'file' => ['sm' => ['gif' => ['url' => 'https://static.klipy.com/sm.gif', 'width' => 120, 'height' => 90, 'size' => 1000]]],
            ]],
        ],
    ])]);
    [$user] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(SearchGifsTool::class, ['query' => 'yay'])
        ->assertOk()
        ->assertSee(['"slug": "yay-1"', '"url": "https://static.klipy.com/sm.gif"', '"has_next": true']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/gifs/search') && str_contains($request->url(), 'q=yay'));
});

test('search_gifs and attach_gif error when gifs are not configured', function (): void {
    config()->set('services.klipy.key', null);
    [$user] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(SearchGifsTool::class, ['query' => 'yay'])
        ->assertHasErrors(['GIF search is not enabled on this instance.']);
    ShoutrrrServer::actingAs($user)->tool(AttachGifTool::class, [
        'catalog' => 'gif', 'slug' => 'x', 'variants' => [['url' => 'https://static.klipy.com/a.gif', 'mime' => 'image/gif', 'width' => 1, 'height' => 1]],
    ])->assertHasErrors(['GIFs are not enabled on this instance.']);
});

test('attach_gif stores the gif in the workspace library', function (): void {
    Storage::fake('local');
    Queue::fake();
    config()->set('services.klipy.key', 'test-key');
    Http::fake(['https://static.klipy.com/*' => Http::response(
        base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'),
        200,
        ['Content-Type' => 'image/gif'],
    )]);
    [$user, $workspace] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(AttachGifTool::class, [
        'catalog' => 'gif',
        'slug' => 'happy-dance-991',
        'title' => 'Happy dance',
        'variants' => [['url' => 'https://static.klipy.com/ok.gif', 'mime' => 'image/gif', 'width' => 320, 'height' => 240, 'bytes' => 40000]],
    ])->assertOk();

    $media = PostMedia::query()->where('workspace_id', $workspace->id)->sole();
    expect($media->mime)->toBe('image/gif')
        ->and($media->alt_text)->toBe('Happy dance')
        ->and($media->post_id)->toBeNull();
    Queue::assertPushed(TriggerKlipyShare::class, fn (TriggerKlipyShare $job): bool => $job->slug === 'happy-dance-991');
});

test('attach_gif refuses a variant off the klipy cdn', function (): void {
    config()->set('services.klipy.key', 'test-key');
    [$user] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(AttachGifTool::class, [
        'catalog' => 'gif',
        'slug' => 'evil',
        'variants' => [['url' => 'https://evil.example.com/a.gif', 'mime' => 'image/gif', 'width' => 1, 'height' => 1, 'bytes' => 100]],
    ])->assertHasErrors(['That is not a supported GIF source.']);

    expect(PostMedia::query()->count())->toBe(0);
});
