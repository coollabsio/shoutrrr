<?php

use App\Dto\Repost\RepostContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;

test('X repost calls the retweets endpoint and returns the source tweet id', function (): void {
    Http::fake([
        'api.twitter.com/2/users/*/retweets' => Http::response(['data' => ['retweeted' => true]], 200),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => 'USER123']);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'remote_id' => 'TWEET456',
    ]);

    $result = app(XConnector::class)->repost(new RepostContext($target, $account, ['access_token' => 'tok']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['TWEET456']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/users/USER123/retweets')
        && $request['tweet_id'] === 'TWEET456'
        && $request->hasHeader('Authorization', 'Bearer tok'));
});

test('X repost maps a failed response to a failure result', function (): void {
    Http::fake(['api.twitter.com/2/users/*/retweets' => Http::response(['title' => 'error'], 403)]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => 'U']);
    $target = PostTarget::factory()->create(['connected_account_id' => $account->id, 'platform' => Platform::X, 'remote_id' => 'T']);

    $result = app(XConnector::class)->repost(new RepostContext($target, $account, ['access_token' => 'tok']));

    expect($result->isSuccessful())->toBeFalse();
});
