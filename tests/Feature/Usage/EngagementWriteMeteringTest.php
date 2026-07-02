<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTargetReply;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Engagement\Connectors\XEngagementConnector;
use Illuminate\Support\Facades\Http;

it('records a reply_like usage event when X likeReply succeeds', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X->value,
        'remote_account_id' => '123',
    ]);

    $reply = PostTargetReply::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'remote_reply_id' => '900',
    ]);

    Http::fake([
        'api.twitter.com/2/users/123/likes' => Http::response(['data' => ['liked' => true]], 200),
    ]);

    app(XEngagementConnector::class)->likeReply($account, $reply, ['access_token' => 'tok']);

    $event = UsageEvent::where('operation', 'reply_like')->firstOrFail();
    expect($event->category)->toBe('external_api')
        ->and($event->platform)->toBe('x')
        ->and($event->workspace_id)->toBe($workspace->id);
});
