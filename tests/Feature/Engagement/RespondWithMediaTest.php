<?php

// tests/Feature/Engagement/RespondWithMediaTest.php
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\Platform;
use App\Enums\SendStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\SendReply;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $this->actingAs($this->user);

    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'token_expires_at' => now()->addHour()]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tok']);
    $this->reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))->for($account, 'account')->create(['platform' => Platform::X]), 'target')
        ->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'remote_reply_id' => '900']);
    $this->media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'kind' => 'image']);
});

test('a text-only reply still posts synchronously (no job)', function () {
    Queue::fake();
    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('postReply')->andReturn(ReplyPostResult::ok('x1'));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    $this->post(route('engagement.respond', $this->reply), ['text' => 'hi'])->assertRedirect();
    Queue::assertNothingPushed();
    expect($this->reply->fresh()->status->value)->toBe('responded');
});

test('a reply with media creates a sending row and dispatches SendReply', function () {
    Queue::fake();

    $this->post(route('engagement.respond', $this->reply), [
        'text' => 'with pic', 'media' => [$this->media->id],
    ])->assertRedirect();

    $ourRow = PostTargetReply::withoutGlobalScopes()->where('is_ours', true)->firstOrFail();
    expect($ourRow->send_status)->toBe(SendStatus::Sending);
    Queue::assertPushed(SendReply::class, 1);
});

test('a media-only reply with empty text is accepted', function () {
    Queue::fake();

    $this->post(route('engagement.respond', $this->reply), [
        'text' => '', 'media' => [$this->media->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    Queue::assertPushed(SendReply::class, 1);
});

test('a reply with neither text nor media is rejected', function () {
    Queue::fake();

    $this->post(route('engagement.respond', $this->reply), [])
        ->assertSessionHasErrors('text');

    Queue::assertNothingPushed();
});

test('a foreign-workspace media id is rejected', function () {
    Queue::fake();

    $otherWorkspace = Workspace::factory()->create();
    $foreignMedia = PostMedia::factory()->create(['workspace_id' => $otherWorkspace->id, 'kind' => 'image']);

    $this->post(route('engagement.respond', $this->reply), [
        'text' => 'with pic', 'media' => [$foreignMedia->id],
    ])->assertSessionHasErrors('media.0');

    Queue::assertNothingPushed();
});

test('SendReply::failed marks the row failed', function () {
    $ourRow = PostTargetReply::factory()->create([
        'workspace_id' => $this->workspace->id, 'post_target_id' => $this->reply->post_target_id,
        'platform' => Platform::X, 'is_ours' => true, 'send_status' => SendStatus::Sending->value,
        'parent_remote_id' => $this->reply->remote_reply_id,
    ]);

    (new SendReply($ourRow->id, $this->reply->id, [$this->media->id], 'with pic', Platform::X))
        ->failed(new RuntimeException('boom'));

    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Failed);
});

test('SendReply posts the media reply and marks it sent', function () {
    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('postReply')->andReturn(ReplyPostResult::ok('rid', 'cid'));
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);

    $ourRow = PostTargetReply::factory()->create([
        'workspace_id' => $this->workspace->id, 'post_target_id' => $this->reply->post_target_id,
        'platform' => Platform::X, 'is_ours' => true, 'send_status' => SendStatus::Sending->value,
        'parent_remote_id' => $this->reply->remote_reply_id,
    ]);

    (new SendReply($ourRow->id, $this->reply->id, [$this->media->id], 'with pic', Platform::X))
        ->handle(app(EngagementConnectorRegistry::class), app(TokenManager::class));

    expect($ourRow->fresh()->send_status)->toBe(SendStatus::Sent);
    expect($ourRow->fresh()->remote_reply_id)->toBe('rid');
});
