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
use Illuminate\Support\Facades\Log;
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

    $this->postJson(route('engagement.like', $this->reply))
        ->assertOk()
        ->assertExactJson(['is_liked' => true]);

    expect($this->reply->fresh()->liked_at)->not->toBeNull();
    expect($this->reply->fresh()->like_remote_id)->toBe('like-1');
});

test('liking an already-liked reply is a no-op that does not call the platform', function (): void {
    $this->reply->forceFill(['liked_at' => now(), 'like_remote_id' => 'like-1'])->save();
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')->never());

    $this->postJson(route('engagement.like', $this->reply))
        ->assertOk()
        ->assertExactJson(['is_liked' => true]);
});

test('a failed like surfaces an error and leaves the reply unliked', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')->andReturn(ReplyActionResult::failed('nope')));

    // 502, never 422: useHttp routes 422 into its validation path, which would
    // swallow this message and skip the client's rollback.
    $this->postJson(route('engagement.like', $this->reply))
        ->assertStatus(502)
        ->assertJson(['status' => 'failed', 'message' => 'nope']);

    expect($this->reply->fresh()->liked_at)->toBeNull();
});

test('a like the platform does not support is a 409 and is not persisted', function (): void {
    // The original bug: X 403s a like when the like.write scope is missing, the
    // connector maps that to `unsupported`, and the old back() made it look OK.
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')
        ->andReturn(ReplyActionResult::unsupported('Missing required OAuth2 scopes: like.write')));

    $this->postJson(route('engagement.like', $this->reply))
        ->assertStatus(409)
        ->assertJson(['status' => 'unsupported', 'message' => 'Missing required OAuth2 scopes: like.write']);

    expect($this->reply->fresh()->liked_at)->toBeNull();
});

test('a rate-limited like is a 429 and is not persisted', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')
        ->andReturn(ReplyActionResult::rateLimited('slow down')));

    $this->postJson(route('engagement.like', $this->reply))
        ->assertStatus(429)
        ->assertJson(['status' => 'rate_limited', 'message' => 'slow down']);

    expect($this->reply->fresh()->liked_at)->toBeNull();
});

test('an auth-expired like is a 403 and is not persisted', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')
        ->andReturn(ReplyActionResult::authExpired('token dead')));

    $this->postJson(route('engagement.like', $this->reply))
        ->assertStatus(403)
        ->assertJson(['status' => 'auth_expired', 'message' => 'token dead']);

    expect($this->reply->fresh()->liked_at)->toBeNull();
});

test('a like with no message falls back to a generic one', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')->andReturn(ReplyActionResult::failed()));

    $this->postJson(route('engagement.like', $this->reply))
        ->assertStatus(502)
        ->assertJson(['status' => 'failed', 'message' => 'Could not like this reply.']);
});

test('a failed like is logged so it is diagnosable from the server', function (): void {
    Log::spy();
    fakeActionConnector(fn ($c) => $c->shouldReceive('likeReply')
        ->andReturn(ReplyActionResult::unsupported('scope missing')));

    $this->postJson(route('engagement.like', $this->reply))->assertStatus(409);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'engagement.action.failed'
            && $context['action'] === 'like'
            && $context['platform'] === 'x'
            && $context['reply_id'] === $this->reply->id
            && $context['workspace_id'] === $this->workspace->id
            && $context['status'] === 'unsupported'
            && $context['message'] === 'scope missing');
});

test('a failed unlike is logged so it is diagnosable from the server', function (): void {
    $this->reply->forceFill(['liked_at' => now(), 'like_remote_id' => 'like-1'])->save();
    Log::spy();
    fakeActionConnector(fn ($c) => $c->shouldReceive('unlikeReply')
        ->andReturn(ReplyActionResult::failed('platform down')));

    $this->deleteJson(route('engagement.unlike', $this->reply))->assertStatus(502);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'engagement.action.failed'
            && $context['action'] === 'unlike'
            && $context['platform'] === 'x'
            && $context['reply_id'] === $this->reply->id
            && $context['status'] === 'failed'
            && $context['message'] === 'platform down');

    // The like must survive an unlike the platform rejected.
    expect($this->reply->fresh()->liked_at)->not->toBeNull();
});

test('unliking clears the like state', function (): void {
    $this->reply->forceFill(['liked_at' => now(), 'like_remote_id' => 'like-1'])->save();
    fakeActionConnector(fn ($c) => $c->shouldReceive('unlikeReply')->once()->andReturn(ReplyActionResult::ok()));

    $this->deleteJson(route('engagement.unlike', $this->reply))
        ->assertOk()
        ->assertExactJson(['is_liked' => false]);

    expect($this->reply->fresh()->liked_at)->toBeNull();
    expect($this->reply->fresh()->like_remote_id)->toBeNull();
});

test('unliking a reply that is not liked is a no-op that does not call the platform', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('unlikeReply')->never());

    $this->deleteJson(route('engagement.unlike', $this->reply))
        ->assertOk()
        ->assertExactJson(['is_liked' => false]);
});

test('deleting our own reply removes it from the platform and the database', function (): void {
    $ours = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::X,
        'remote_reply_id' => '901', 'is_ours' => true,
    ]);
    fakeActionConnector(fn ($c) => $c->shouldReceive('deleteReply')->once()->andReturn(ReplyActionResult::ok()));

    $this->deleteJson(route('engagement.destroy', $ours))->assertNoContent();

    expect(PostTargetReply::withoutGlobalScopes()->whereKey($ours->id)->exists())->toBeFalse();
});

test('a failed delete keeps the reply', function (): void {
    $ours = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::X,
        'remote_reply_id' => '901', 'is_ours' => true,
    ]);
    fakeActionConnector(fn ($c) => $c->shouldReceive('deleteReply')->andReturn(ReplyActionResult::failed('platform down')));

    $this->deleteJson(route('engagement.destroy', $ours))
        ->assertStatus(502)
        ->assertJson(['status' => 'failed', 'message' => 'platform down']);

    expect(PostTargetReply::withoutGlobalScopes()->whereKey($ours->id)->exists())->toBeTrue();
});

test('deleting a reply that is not ours is forbidden', function (): void {
    fakeActionConnector(fn ($c) => $c->shouldReceive('deleteReply')->never());

    $this->deleteJson(route('engagement.destroy', $this->reply))->assertForbidden();
});

test('liking a reply in another workspace 404s', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $foreign = PostTargetReply::factory()->create([
        'workspace_id' => $otherWorkspace->id, 'platform' => Platform::X, 'is_ours' => false,
    ]);

    $this->postJson(route('engagement.like', $foreign))->assertNotFound();
});
