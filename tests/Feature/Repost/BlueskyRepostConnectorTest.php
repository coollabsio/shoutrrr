<?php

use App\Dto\Repost\RepostContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use Illuminate\Support\Facades\Http;

test('Bluesky repost creates a repost record with the subject strongRef', function (): void {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(['cid' => 'CID1', 'uri' => 'at://did/app.bsky.feed.post/rkey'], 200),
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did/app.bsky.feed.repost/newrkey', 'cid' => 'CID2'], 200),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:plc:abc']);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'remote_id' => 'at://did:plc:abc/app.bsky.feed.post/xyz',
    ]);

    $credentials = ['session' => ['pds' => 'https://bsky.social', 'accessJwt' => 'jwt']];

    $result = app(BlueskyPublishConnector::class)->repost(new RepostContext($target, $account, $credentials));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['at://did/app.bsky.feed.repost/newrkey']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        return $request['collection'] === 'app.bsky.feed.repost'
            && $request['record']['$type'] === 'app.bsky.feed.repost'
            && $request['record']['subject']['uri'] === 'at://did:plc:abc/app.bsky.feed.post/xyz'
            && $request['record']['subject']['cid'] === 'CID1';
    });
});
