<?php

use App\Dto\Repost\RepostContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\LinkedInConnector;
use Illuminate\Support\Facades\Http;

test('LinkedIn repost posts a reshare referencing the parent urn', function (): void {
    Http::fake([
        'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:999']),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn, 'remote_account_id' => 'PERSON1']);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn,
        'remote_id' => 'urn:li:share:111',
    ]);

    $result = app(LinkedInConnector::class)->repost(new RepostContext($target, $account, ['access_token' => 'tok']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['urn:li:share:999']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && $request['author'] === 'urn:li:person:PERSON1'
        && $request['reshareContext']['parent'] === 'urn:li:share:111');
});
