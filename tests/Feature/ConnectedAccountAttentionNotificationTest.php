<?php

use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountNeedsAttentionNotification;
use App\Notifications\PublishFailedNotification;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

test('background connection failure notifies the workspace owner and connecting user', function () {
    $owner = User::factory()->create();
    $connector = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['connected_by_user_id' => $connector->id]);
    Notification::fake();

    $account->update(['status' => ConnectedAccountStatus::NeedsAttention]);

    Notification::assertSentTo([$owner, $connector], AccountNeedsAttentionNotification::class);
    Notification::assertCount(2);
});

test('repeated connection failures notify once until the account reconnects', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['connected_by_user_id' => $owner->id]);
    Notification::fake();

    $account->update(['status' => ConnectedAccountStatus::NeedsAttention]);
    $account->update(['refresh_failure_reason' => 'Invalid refresh token']);
    $account->update(['status' => ConnectedAccountStatus::NeedsAttention]);

    Notification::assertSentToTimes($owner, AccountNeedsAttentionNotification::class, 1);
    Notification::assertCount(1);

    $account->update(['status' => ConnectedAccountStatus::Active]);
    $account->update(['status' => ConnectedAccountStatus::NeedsAttention]);

    Notification::assertSentToTimes($owner, AccountNeedsAttentionNotification::class, 2);
});

test('healthy account updates do not send reconnect notifications', function () {
    $account = ConnectedAccount::factory()->create();
    Notification::fake();

    $account->update(['last_refreshed_at' => now()]);

    Notification::assertNothingSent();
});

test('auth-expired publish tells the owner and connector to reconnect and the author that the post failed', function () {
    Bus::fake();
    $owner = User::factory()->create();
    $connector = User::factory()->create();
    $author = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $target = publishTarget();
    $target->post()->firstOrFail()->forceFill(['workspace_id' => $workspace->id, 'author_id' => $author->id])->save();
    $target->account()->firstOrFail()->forceFill(['workspace_id' => $workspace->id, 'connected_by_user_id' => $connector->id])->save();
    Http::fake(['https://api.twitter.com/2/oauth2/token' => Http::response([], 400)]);
    bindConnector(PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401));
    Notification::fake();

    (new PublishPostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    Notification::assertSentTo([$owner, $connector], AccountNeedsAttentionNotification::class);
    Notification::assertNotSentTo($author, AccountNeedsAttentionNotification::class);
    Notification::assertSentTo($author, PublishFailedNotification::class);
});
