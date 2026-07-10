<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;

function disabledPublishTarget(): PostTarget
{
    $post = Post::factory()->create(['status' => PostStatus::Publishing]);
    $account = ConnectedAccount::factory()->disabled()->create([
        'platform' => 'x',
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => 'x',
        'sections' => ['hello'],
        'status' => 'pending',
    ]);
}

it('skips a target whose account is disabled instead of publishing', function () {
    $target = disabledPublishTarget();

    app(PublishPostTarget::class, ['target' => $target])->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->fresh()->status)->toBe(PostTargetStatus::Skipped)
        ->and($target->fresh()->attemptLogs()->count())->toBe(0);
});
