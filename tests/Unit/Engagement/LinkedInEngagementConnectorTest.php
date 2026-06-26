<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\LinkedInEngagementConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function linkedinConnector(): LinkedInEngagementConnector
{
    return new LinkedInEngagementConnector(app(Factory::class));
}

function linkedinAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn,
        'remote_account_id' => 'OWNER',
    ]);
}

test('fetchReplies parses comments and excludes the owner', function () {
    Http::fake([
        'api.linkedin.com/rest/socialActions/*' => Http::response([
            'elements' => [
                [
                    'actor' => 'urn:li:person:FAN1',
                    'commentUrn' => 'urn:li:comment:(urn:li:share:123,900)',
                    'created' => ['time' => 1_700_000_000_000],
                    'message' => ['text' => 'great post'],
                ],
                [
                    'actor' => 'urn:li:person:OWNER',
                    'commentUrn' => 'urn:li:comment:(urn:li:share:123,901)',
                    'created' => ['time' => 1_700_000_001_000],
                    'message' => ['text' => 'my own comment'],
                ],
            ],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn, 'remote_id' => 'urn:li:share:123']);

    $result = linkedinConnector()->fetchReplies(linkedinAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(1);
    expect($result->replies[0]->remoteReplyId)->toBe('urn:li:comment:(urn:li:share:123,900)');
    expect($result->replies[0]->authorHandle)->toBe('FAN1');
    expect($result->replies[0]->parentRemoteId)->toBe('urn:li:share:123');
    expect($result->replies[0]->text)->toBe('great post');
});

test('fetchReplies treats 404 (no comments yet) as an empty result', function () {
    Http::fake(['api.linkedin.com/rest/socialActions/*' => Http::response(['message' => 'Not Found'], 404)]);

    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn, 'remote_id' => 'urn:li:share:123']);

    $result = linkedinConnector()->fetchReplies(linkedinAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(0);
});

test('fetchReplies maps 403 (no permission) to unsupported', function () {
    Http::fake(['api.linkedin.com/rest/socialActions/*' => Http::response(['message' => 'Not enough permissions'], 403)]);

    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn, 'remote_id' => 'urn:li:share:123']);

    $result = linkedinConnector()->fetchReplies(linkedinAccount(), $target, ['access_token' => 't'], null);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('fetchReplies drops comments at or before since', function () {
    Http::fake([
        'api.linkedin.com/rest/socialActions/*' => Http::response([
            'elements' => [[
                'actor' => 'urn:li:person:FAN1',
                'commentUrn' => 'urn:li:comment:(urn:li:share:123,900)',
                'created' => ['time' => 1_700_000_000_000],
                'message' => ['text' => 'old'],
            ]],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn, 'remote_id' => 'urn:li:share:123']);
    $since = CarbonImmutable::createFromTimestampMs(1_700_000_500_000);

    $result = linkedinConnector()->fetchReplies(linkedinAccount(), $target, ['access_token' => 't'], $since);

    expect($result->replies)->toHaveCount(0);
});

test('postReply creates a nested comment on the post', function () {
    Http::fake([
        'api.linkedin.com/rest/socialActions/*' => Http::response([
            'commentUrn' => 'urn:li:comment:(urn:li:share:123,999)',
        ]),
    ]);

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::LinkedIn,
        'remote_reply_id' => 'urn:li:comment:(urn:li:share:123,900)',
        'parent_remote_id' => 'urn:li:share:123',
    ]);

    $result = linkedinConnector()->postReply(linkedinAccount(), $parent, 'thanks!', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('urn:li:comment:(urn:li:share:123,999)');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/comments')
        && $req['actor'] === 'urn:li:person:OWNER'
        && $req['object'] === 'urn:li:share:123'
        && $req['message']['text'] === 'thanks!'
        && $req['parentComment'] === 'urn:li:comment:(urn:li:share:123,900)');
});

test('postReply declines media (LinkedIn comments cannot carry attachments)', function () {
    Http::preventStrayRequests();

    $parent = PostTargetReply::factory()->create([
        'platform' => Platform::LinkedIn,
        'remote_reply_id' => 'urn:li:comment:(urn:li:share:123,900)',
        'parent_remote_id' => 'urn:li:share:123',
    ]);

    $result = linkedinConnector()->postReply(
        linkedinAccount(),
        $parent,
        'with pic',
        ['access_token' => 't'],
        [PostMedia::factory()->make()],
    );

    expect($result->status)->toBe(EngagementStatus::Failed);
});
