<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Engagement\Connectors\XEngagementConnector;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    app(InstanceSettings::class)->update(['usage_tracking_enabled' => true]);
});

it('bills reply fetch per returned tweet, deduped daily', function (): void {
    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X, 'handle' => '@acme']);
    $target = PostTarget::factory()->for($account, 'account')->create(['platform' => Platform::X, 'remote_id' => '123']);

    Http::fake([
        'api.twitter.com/2/tweets/search/recent*' => Http::response([
            'data' => [
                ['id' => '901', 'author_id' => 'u1', 'created_at' => '2026-07-18T00:00:00Z'],
                ['id' => '902', 'author_id' => 'u1', 'created_at' => '2026-07-18T00:00:00Z'],
            ],
            'includes' => ['users' => [['id' => 'u1', 'username' => 'bob', 'name' => 'Bob']]],
        ]),
    ]);

    $connector = app(XEngagementConnector::class);
    $connector->fetchReplies($account, $target, ['access_token' => 't'], null);
    $connector->fetchReplies($account, $target, ['access_token' => 't'], null);

    $weights = UsageEvent::query()->where('operation', UsageOperation::REPLIES_FETCH)->orderBy('occurred_at')->pluck('quota_weight');

    expect($weights->all())->toBe([2, 0]);
});
