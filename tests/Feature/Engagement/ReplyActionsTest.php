<?php

use App\Dto\Engagement\ReplyActionResult;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Engagement\Contracts\EngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;
use Illuminate\Support\Facades\Context;
use Mockery\MockInterface;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $this->actingAs($this->user);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    $this->target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))
        ->for($account, 'account')
        ->create(['platform' => Platform::X, 'remote_id' => '500']);

    $this->reply = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'remote_reply_id' => '900',
        'is_ours' => false,
    ]);
});

/**
 * @param  callable(MockInterface): void  $expectations
 */
function fakeActionConnector(callable $expectations): void
{
    $connector = Mockery::mock(EngagementConnector::class);
    $expectations($connector);
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);
}

test('liking a reply records liked_at and the like remote id', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')->once()->andReturn(ReplyActionResult::ok('like-1')));

    $this->post(route('engagement.like', $this->reply))->assertRedirect();

    expect($this->reply->fresh()->liked_at)->not->toBeNull();
    expect($this->reply->fresh()->like_remote_id)->toBe('like-1');
});

test('liking an already-liked reply is a no-op that does not call the platform', function (): void {
    $this->reply->forceFill(['liked_at' => now(), 'like_remote_id' => 'like-1'])->save();
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')->never());

    $this->post(route('engagement.like', $this->reply))->assertRedirect();
});

test('a failed like surfaces an error and leaves the reply unliked', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')->andReturn(ReplyActionResult::failed('nope')));

    $this->post(route('engagement.like', $this->reply))->assertSessionHas('error');

    expect($this->reply->fresh()->liked_at)->toBeNull();
});

test('unliking clears the like state', function (): void {
    $this->reply->forceFill(['liked_at' => now(), 'like_remote_id' => 'like-1'])->save();
    fakeActionConnector(fn ($c) => $c->shouldReceive('unlikeReply')->once()->andReturn(ReplyActionResult::ok()));

    $this->delete(route('engagement.unlike', $this->reply))->assertRedirect();

    expect($this->reply->fresh()->liked_at)->toBeNull();
    expect($this->reply->fresh()->like_remote_id)->toBeNull();
});

test('deleting our own reply removes it from the platform and the database', function (): void {
    $ours = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::X,
        'remote_reply_id' => '901', 'is_ours' => true,
    ]);
    fakeActionConnector(fn ($c) => $c->shouldReceive('deleteReply')->once()->andReturn(ReplyActionResult::ok()));

    $this->delete(route('engagement.destroy', $ours))->assertRedirect();

    expect(PostTargetReply::withoutGlobalScopes()->whereKey($ours->id)->exists())->toBeFalse();
});

test('a failed delete keeps the reply', function (): void {
    $ours = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::X,
        'remote_reply_id' => '901', 'is_ours' => true,
    ]);
    fakeActionConnector(fn ($c) => $c->shouldReceive('deleteReply')->andReturn(ReplyActionResult::failed('platform down')));

    $this->delete(route('engagement.destroy', $ours))->assertSessionHas('error');

    expect(PostTargetReply::withoutGlobalScopes()->whereKey($ours->id)->exists())->toBeTrue();
});

test('deleting a reply that is not ours is forbidden', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('deleteReply')->never());

    $this->delete(route('engagement.destroy', $this->reply))->assertForbidden();
});

test('liking a reply in another workspace 404s', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $foreign = PostTargetReply::factory()->create([
        'workspace_id' => $otherWorkspace->id, 'platform' => Platform::X, 'is_ours' => false,
    ]);

    $this->post(route('engagement.like', $foreign))->assertNotFound();
});
