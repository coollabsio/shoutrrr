<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\LinkedInEngagementConnector;
use Illuminate\Support\Facades\Http;

test('linkedin connector reports unsupported without hitting the network', function () {
    Http::preventStrayRequests();

    $connector = new LinkedInEngagementConnector;
    $account = ConnectedAccount::factory()->create(['platform' => Platform::LinkedIn]);

    $fetch = $connector->fetchReplies($account, PostTarget::factory()->create(['platform' => Platform::LinkedIn]), [], null);
    $post = $connector->postReply($account, PostTargetReply::factory()->create(['platform' => Platform::LinkedIn]), 'hi', []);

    expect($fetch->status)->toBe(EngagementStatus::Unsupported);
    expect($post->status)->toBe(EngagementStatus::Unsupported);
});
