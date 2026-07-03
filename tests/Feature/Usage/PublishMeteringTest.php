<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;

function makePublishTarget(ConnectedAccount $account): PostTarget
{
    return PostTarget::factory()->for($account, 'account')->create(['platform' => Platform::X->value]);
}

it('records a post event when X publishes a tweet', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);

    Http::fake([
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']], 200, ['x-rate-limit-remaining' => '9']),
    ]);

    $context = new PublishContext(
        target: makePublishTarget($account),
        segments: ['hello world'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'tok'],
    );

    app(XConnector::class)->publish($context);

    $event = UsageEvent::where('operation', 'post')->firstOrFail();
    expect($event->platform)->toBe('x')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue();
});
