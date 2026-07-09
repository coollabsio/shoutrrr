<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\DeletePostTarget;
use App\Jobs\SyncExternalAccountPosts;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ExternalPosts\XExternalPostImporter;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function externalSyncOwner(): array
{
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$owner, $workspace];
}

function externalSyncPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

afterEach(function (): void {
    Date::setTestNow();
});

test('x external post sync imports unseen account posts as published targets', function () {
    [$owner, $workspace] = externalSyncOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $owner->id,
        'remote_account_id' => '123',
        'sync_external_posts' => true,
    ]);

    Http::fake([
        'https://api.twitter.com/2/users/123/tweets*' => Http::response([
            'data' => [[
                'id' => '9001',
                'text' => 'posted directly on X',
                'created_at' => '2026-07-09T10:00:00.000Z',
                'public_metrics' => [
                    'like_count' => 7,
                    'reply_count' => 2,
                    'retweet_count' => 1,
                    'quote_count' => 3,
                    'impression_count' => 99,
                ],
            ]],
        ]),
    ]);

    $imported = app(XExternalPostImporter::class)->import($account, ['access_token' => 'token']);

    expect($imported)->toBe(1)
        ->and(Post::query()->count())->toBe(1);

    $post = Post::query()->firstOrFail();
    $target = PostTarget::query()->firstOrFail();

    expect($post->workspace_id)->toBe($workspace->id)
        ->and($post->author_id)->toBe($owner->id)
        ->and($post->base_text)->toBe('posted directly on X')
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at?->toIso8601String())->toBe('2026-07-09T10:00:00+00:00')
        ->and($target->connected_account_id)->toBe($account->id)
        ->and($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('9001')
        ->and($target->remote_ids)->toBe(['9001'])
        ->and($target->imported_from_remote)->toBeTrue()
        ->and($target->likes)->toBe(7)
        ->and($target->comments)->toBe(2)
        ->and($target->reposts)->toBe(4)
        ->and($target->impressions)->toBe(99)
        ->and($target->metrics_status)->toBe(MetricsStatus::Ok)
        ->and($account->fresh()->external_posts_synced_at)->not->toBeNull();

    Http::assertSent(function ($request): bool {
        $query = $request->data();
        $expansions = (string) ($query['expansions'] ?? '');

        return str_contains($request->url(), '/2/users/123/tweets')
            && str_contains($expansions, 'attachments.media_keys')
            && str_contains($expansions, 'referenced_tweets.id')
            && ($query['media.fields'] ?? null) !== null;
    });
});

test('x external post sync imports media and quoted tweet context', function () {
    Storage::fake('public');

    [$owner, $workspace] = externalSyncOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $owner->id,
        'remote_account_id' => '123',
        'sync_external_posts' => true,
    ]);

    Http::fake([
        'https://api.twitter.com/2/users/123/tweets*' => Http::response([
            'data' => [[
                'id' => '9002',
                'text' => 'A quote with my own image',
                'created_at' => '2026-07-09T10:00:00.000Z',
                'attachments' => ['media_keys' => ['3_main']],
                'referenced_tweets' => [['type' => 'quoted', 'id' => '8001']],
                'public_metrics' => [],
            ]],
            'includes' => [
                'media' => [
                    [
                        'media_key' => '3_main',
                        'type' => 'photo',
                        'url' => 'https://example.com/main.png',
                        'alt_text' => 'main alt',
                        'width' => 640,
                        'height' => 480,
                    ],
                    [
                        'media_key' => '3_quote',
                        'type' => 'photo',
                        'url' => 'https://example.com/quote.png',
                        'alt_text' => 'quote alt',
                        'width' => 320,
                        'height' => 240,
                    ],
                ],
                'tweets' => [[
                    'id' => '8001',
                    'text' => 'quoted post body',
                    'author_id' => 'quoted-user',
                    'attachments' => ['media_keys' => ['3_quote']],
                ]],
                'users' => [[
                    'id' => 'quoted-user',
                    'name' => 'Quoted User',
                    'username' => 'quoted_user',
                    'profile_image_url' => 'https://example.com/avatar.jpg',
                ]],
            ],
        ]),
        'https://example.com/*' => Http::response(externalSyncPng(), 200, ['Content-Type' => 'image/png']),
    ]);

    $imported = app(XExternalPostImporter::class)->import($account, ['access_token' => 'token']);

    $post = Post::query()->firstOrFail();
    $media = PostMedia::query()->firstOrFail();
    $quotedTweet = $post->external_context['x']['quoted_tweet'];

    expect($imported)->toBe(1)
        ->and(Post::query()->count())->toBe(1)
        ->and(PostMedia::query()->count())->toBe(1)
        ->and($media->post_id)->toBe($post->id)
        ->and($media->alt_text)->toBe('main alt')
        ->and($media->position)->toBe(0)
        ->and($media->mime)->toBe('image/png')
        ->and($quotedTweet['id'])->toBe('8001')
        ->and($quotedTweet['text'])->toBe('quoted post body')
        ->and($quotedTweet['author_name'])->toBe('Quoted User')
        ->and($quotedTweet['author_username'])->toBe('quoted_user')
        ->and($quotedTweet['media'][0]['url'])->toBe('https://example.com/quote.png')
        ->and($quotedTweet['media'][0]['alt_text'])->toBe('quote alt');

    Storage::disk('public')->assertExists($media->path);
});

test('x external post sync updates known post metrics without duplicating records', function () {
    [$owner, $workspace] = externalSyncOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $owner->id,
        'remote_account_id' => '123',
        'sync_external_posts' => true,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $owner->id,
        'status' => PostStatus::Published->value,
        'published_at' => now(),
    ]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Published->value,
        'remote_id' => '9001',
        'remote_ids' => ['9001'],
        'likes' => 1,
    ]);

    Http::fake([
        'https://api.twitter.com/2/users/123/tweets*' => Http::response([
            'data' => [[
                'id' => '9001',
                'text' => 'same remote post',
                'created_at' => '2026-07-09T10:00:00.000Z',
                'public_metrics' => ['like_count' => 8, 'reply_count' => 1],
            ]],
        ]),
    ]);

    $imported = app(XExternalPostImporter::class)->import($account, ['access_token' => 'token']);

    expect($imported)->toBe(0)
        ->and(Post::query()->count())->toBe(1)
        ->and(PostTarget::query()->count())->toBe(1)
        ->and($target->fresh()->likes)->toBe(8)
        ->and($target->fresh()->comments)->toBe(1);
});

test('x external post sync enriches known imported posts with media and quote context', function () {
    Storage::fake('public');

    [$owner, $workspace] = externalSyncOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $owner->id,
        'remote_account_id' => '123',
        'sync_external_posts' => true,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $owner->id,
        'status' => PostStatus::Published->value,
        'published_at' => now(),
    ]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Published->value,
        'remote_id' => '9001',
        'remote_ids' => ['9001'],
        'imported_from_remote' => true,
    ]);

    Http::fake([
        'https://api.twitter.com/2/users/123/tweets*' => Http::response([
            'data' => [[
                'id' => '9001',
                'text' => 'known remote post',
                'created_at' => '2026-07-09T10:00:00.000Z',
                'attachments' => ['media_keys' => ['3_main']],
                'referenced_tweets' => [['type' => 'quoted', 'id' => '8001']],
                'public_metrics' => ['like_count' => 3],
            ]],
            'includes' => [
                'media' => [[
                    'media_key' => '3_main',
                    'type' => 'photo',
                    'url' => 'https://example.com/main.png',
                    'alt_text' => 'main alt',
                ]],
                'tweets' => [[
                    'id' => '8001',
                    'text' => 'quoted post body',
                    'author_id' => 'quoted-user',
                ]],
                'users' => [[
                    'id' => 'quoted-user',
                    'name' => 'Quoted User',
                    'username' => 'quoted_user',
                ]],
            ],
        ]),
        'https://example.com/*' => Http::response(externalSyncPng(), 200, ['Content-Type' => 'image/png']),
    ]);

    $imported = app(XExternalPostImporter::class)->import($account, ['access_token' => 'token']);
    $post->refresh();

    expect($imported)->toBe(0)
        ->and(Post::query()->count())->toBe(1)
        ->and(PostTarget::query()->count())->toBe(1)
        ->and(PostMedia::query()->count())->toBe(1)
        ->and($post->external_context['x']['quoted_tweet']['id'])->toBe('8001')
        ->and($post->external_context['x']['quoted_tweet']['author_username'])->toBe('quoted_user');
});

test('x external post sync uses the configured lookback window', function () {
    Date::setTestNow('2026-07-09 12:00:00');
    app(InstanceSettings::class)->update(['external_posts_sync_lookback_days' => 30]);

    [$owner, $workspace] = externalSyncOwner();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $owner->id,
        'remote_account_id' => '123',
        'sync_external_posts' => true,
    ]);

    Http::fake([
        'https://api.twitter.com/2/users/123/tweets*' => Http::response([
            'data' => [
                [
                    'id' => 'old-remote-post',
                    'text' => 'too old',
                    'created_at' => '2026-05-01T10:00:00.000Z',
                    'public_metrics' => [],
                ],
                [
                    'id' => 'recent-remote-post',
                    'text' => 'recent enough',
                    'created_at' => '2026-07-01T10:00:00.000Z',
                    'public_metrics' => [],
                ],
            ],
        ]),
    ]);

    $imported = app(XExternalPostImporter::class)->import($account, ['access_token' => 'token']);

    expect($imported)->toBe(1)
        ->and(Post::query()->count())->toBe(1)
        ->and(Post::query()->firstOrFail()->base_text)->toBe('recent enough');

    Http::assertSent(function ($request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return ($query['start_time'] ?? null) === '2026-06-09T12:00:00Z';
    });
});

test('external post sync command dispatches only enabled active x accounts', function () {
    Queue::fake();

    $enabled = ConnectedAccount::factory()->create(['sync_external_posts' => true]);
    ConnectedAccount::factory()->create(['sync_external_posts' => false]);
    ConnectedAccount::factory()->bluesky()->create(['sync_external_posts' => true]);

    test()->artisan('external-posts:sync')->assertSuccessful();

    Queue::assertPushed(SyncExternalAccountPosts::class, 1);
    Queue::assertPushed(
        SyncExternalAccountPosts::class,
        fn (SyncExternalAccountPosts $job): bool => $job->account->is($enabled),
    );
});

test('deleting an imported external post queues remote deletion', function () {
    Queue::fake();

    [$owner, $workspace] = externalSyncOwner();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $owner->id,
        'status' => PostStatus::Published->value,
        'published_at' => now(),
    ]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Published->value,
        'remote_id' => '9001',
        'remote_ids' => ['9001'],
        'imported_from_remote' => true,
    ]);

    test()->actingAs($owner)
        ->delete(route('posts.destroy', $post))
        ->assertRedirect(route('posts.index'));

    Queue::assertPushed(DeletePostTarget::class, 1);
    Queue::assertPushed(
        DeletePostTarget::class,
        fn (DeletePostTarget $job): bool => $job->target->is($target),
    );
    expect($target->fresh()->status)->toBe(PostTargetStatus::Deleting);
});
