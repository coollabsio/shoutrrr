<?php

use App\Dto\Engagement\ReplyActionResult;
use App\Dto\Engagement\ReplyPostResult;
use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\ArchiveReplyTool;
use App\Mcp\Tools\DeleteReplyTool;
use App\Mcp\Tools\GetReplyThreadTool;
use App\Mcp\Tools\LikeReplyTool;
use App\Mcp\Tools\ListRepliesTool;
use App\Mcp\Tools\MarkRepliesReadTool;
use App\Mcp\Tools\RespondToReplyTool;
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
use Mockery\MockInterface;

beforeEach(function (): void {
    config()->set('engagement.enabled', true);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    bindTokenToWorkspace($this->user, $this->workspace);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'handle' => 'shoutrrr',
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tok']);

    $this->target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))
        ->for($account, 'account')
        ->create(['platform' => Platform::X, 'remote_id' => '500']);

    $this->reply = mcpInboundReply($this->target, '900', 'Love this!');
});

function mcpInboundReply(PostTarget $target, string $remoteId, string $text, array $attributes = []): PostTargetReply
{
    return PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $target->post->workspace_id,
        'platform' => Platform::X,
        'remote_reply_id' => $remoteId,
        'parent_remote_id' => $target->remote_id,
        'text' => $text,
        'is_ours' => false,
        'read_at' => null,
        'status' => ReplyStatus::Pending->value,
        ...$attributes,
    ]);
}

/**
 * @param  callable(MockInterface): void  $expectations
 */
function fakeMcpEngagementConnector(callable $expectations): void
{
    $connector = Mockery::mock(EngagementConnector::class);
    $expectations($connector);
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);
}

test('list_replies lists live conversations and honours the unread and archived filters', function (): void {
    mcpInboundReply($this->target, '901', 'Already seen', ['read_at' => now()]);
    mcpInboundReply($this->target, '902', 'Old news', ['status' => ReplyStatus::Archived->value]);

    ShoutrrrServer::actingAs($this->user)->tool(ListRepliesTool::class, [])
        ->assertOk()
        ->assertSee(['Love this!', 'Already seen', '"total": 2'])
        ->assertDontSee('Old news');

    ShoutrrrServer::actingAs($this->user)->tool(ListRepliesTool::class, ['unread' => true])
        ->assertOk()
        ->assertSee(['Love this!', '"total": 1'])
        ->assertDontSee('Already seen');

    ShoutrrrServer::actingAs($this->user)->tool(ListRepliesTool::class, ['archived' => true])
        ->assertOk()
        ->assertSee(['Old news', '"total": 1'])
        ->assertDontSee('Love this!');
});

test('get_reply_thread returns the conversation oldest first and marks it read', function (): void {
    $child = mcpInboundReply($this->target, '903', 'Follow-up question', [
        'parent_remote_id' => '900',
        'remote_created_at' => now()->addMinute(),
    ]);

    ShoutrrrServer::actingAs($this->user)->tool(GetReplyThreadTool::class, ['reply_id' => $child->id])
        ->assertOk()
        ->assertSee(['Love this!', 'Follow-up question']);

    expect($this->reply->fresh()->read_at)->not->toBeNull()
        ->and($child->fresh()->read_at)->not->toBeNull();
});

test('respond_to_reply refuses to post without confirm', function (): void {
    fakeMcpEngagementConnector(fn ($c) => $c->shouldReceive('postReply')->never());

    ShoutrrrServer::actingAs($this->user)->tool(RespondToReplyTool::class, [
        'reply_id' => $this->reply->id,
        'text' => 'Thanks!',
    ])->assertHasErrors(['confirm set to true']);

    expect(PostTargetReply::query()->where('is_ours', true)->exists())->toBeFalse();
});

test('respond_to_reply posts the reply and records our row', function (): void {
    fakeMcpEngagementConnector(fn ($c) => $c->shouldReceive('postReply')
        ->once()
        ->withArgs(fn ($account, $reply, $text) => $reply->is($this->reply) && $text === 'Thanks!')
        ->andReturn(ReplyPostResult::ok('remote-ours')));

    ShoutrrrServer::actingAs($this->user)->tool(RespondToReplyTool::class, [
        'reply_id' => $this->reply->id,
        'text' => 'Thanks!',
        'confirm' => true,
    ])->assertOk()->assertSee(['Thanks!', 'remote-ours']);

    $ours = PostTargetReply::query()->where('is_ours', true)->sole();
    expect($ours->remote_reply_id)->toBe('remote-ours')
        ->and($ours->parent_remote_id)->toBe('900')
        ->and($this->reply->fresh()->status)->toBe(ReplyStatus::Responded);
});

test('respond_to_reply enforces the account text limit', function (): void {
    fakeMcpEngagementConnector(fn ($c) => $c->shouldReceive('postReply')->never());

    ShoutrrrServer::actingAs($this->user)->tool(RespondToReplyTool::class, [
        'reply_id' => $this->reply->id,
        'text' => str_repeat('a', 30_000),
        'confirm' => true,
    ])->assertHasErrors();
});

test('respond_to_reply surfaces a platform failure', function (): void {
    fakeMcpEngagementConnector(fn ($c) => $c->shouldReceive('postReply')->andReturn(ReplyPostResult::rateLimited('slow down')));

    ShoutrrrServer::actingAs($this->user)->tool(RespondToReplyTool::class, [
        'reply_id' => $this->reply->id,
        'text' => 'Thanks!',
        'confirm' => true,
    ])->assertHasErrors(['slow down']);

    expect(PostTargetReply::query()->where('is_ours', true)->exists())->toBeFalse();
});

test('like_reply likes and unlikes on the platform', function (): void {
    fakeMcpEngagementConnector(function ($c): void {
        $c->shouldReceive('likeReply')->once()->andReturn(ReplyActionResult::ok('like-1'));
        $c->shouldReceive('unlikeReply')->once()
            ->withArgs(fn ($account, $reply, $likeRemoteId) => $likeRemoteId === 'like-1')
            ->andReturn(ReplyActionResult::ok());
    });

    ShoutrrrServer::actingAs($this->user)->tool(LikeReplyTool::class, ['reply_id' => $this->reply->id, 'liked' => true])
        ->assertOk()->assertSee('"is_liked":true');
    expect($this->reply->fresh()->like_remote_id)->toBe('like-1');

    ShoutrrrServer::actingAs($this->user)->tool(LikeReplyTool::class, ['reply_id' => $this->reply->id, 'liked' => false])
        ->assertOk()->assertSee('"is_liked":false');
    expect($this->reply->fresh()->liked_at)->toBeNull();
});

test('archive_reply archives the conversation', function (): void {
    ShoutrrrServer::actingAs($this->user)->tool(ArchiveReplyTool::class, ['reply_id' => $this->reply->id])
        ->assertOk();

    expect($this->reply->fresh()->status)->toBe(ReplyStatus::Archived);
});

test('mark_replies_read marks one conversation or everything', function (): void {
    $other = mcpInboundReply($this->target, '904', 'Another thread');

    ShoutrrrServer::actingAs($this->user)->tool(MarkRepliesReadTool::class, ['reply_id' => $this->reply->id])
        ->assertOk();
    expect($this->reply->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull();

    ShoutrrrServer::actingAs($this->user)->tool(MarkRepliesReadTool::class, ['all' => true])
        ->assertOk()->assertSee('"marked_read":1');
    expect($other->fresh()->read_at)->not->toBeNull();
});

test('mark_replies_read needs a reply id or all', function (): void {
    ShoutrrrServer::actingAs($this->user)->tool(MarkRepliesReadTool::class, [])->assertHasErrors();
});

test('delete_reply only deletes our own replies, after confirm', function (): void {
    $ours = mcpInboundReply($this->target, '950', 'Our answer', ['is_ours' => true, 'parent_remote_id' => '900']);
    fakeMcpEngagementConnector(fn ($c) => $c->shouldReceive('deleteReply')->once()->andReturn(ReplyActionResult::ok()));

    ShoutrrrServer::actingAs($this->user)->tool(DeleteReplyTool::class, ['reply_id' => $this->reply->id, 'confirm' => true])
        ->assertHasErrors(['Only replies sent from this workspace']);

    ShoutrrrServer::actingAs($this->user)->tool(DeleteReplyTool::class, ['reply_id' => $ours->id])
        ->assertHasErrors(['confirm set to true']);
    expect($ours->fresh())->not->toBeNull();

    ShoutrrrServer::actingAs($this->user)->tool(DeleteReplyTool::class, ['reply_id' => $ours->id, 'confirm' => true])
        ->assertOk();
    expect(PostTargetReply::withoutGlobalScopes()->find($ours->id))->toBeNull()
        ->and($this->reply->fresh())->not->toBeNull();
});

test('reply tools cannot see or touch another workspace\'s replies', function (string $tool, array $arguments): void {
    $foreignWorkspace = Workspace::factory()->create();
    $foreignTarget = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $foreignWorkspace->id]))
        ->for(ConnectedAccount::factory()->create(['workspace_id' => $foreignWorkspace->id, 'platform' => Platform::X]), 'account')
        ->create(['platform' => Platform::X, 'remote_id' => '700']);
    $foreign = mcpInboundReply($foreignTarget, '701', 'Foreign secret', ['is_ours' => true]);
    fakeMcpEngagementConnector(function ($c): void {
        $c->shouldReceive('postReply', 'likeReply', 'deleteReply')->never();
    });

    ShoutrrrServer::actingAs($this->user)->tool($tool, ['reply_id' => $foreign->id, ...$arguments])
        ->assertHasErrors(['No reply with that id exists in this workspace.']);

    $fresh = PostTargetReply::withoutGlobalScopes()->find($foreign->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->read_at)->toBeNull()
        ->and($fresh->liked_at)->toBeNull()
        ->and($fresh->status)->toBe(ReplyStatus::Pending);
})->with([
    'get_reply_thread' => [GetReplyThreadTool::class, []],
    'respond_to_reply' => [RespondToReplyTool::class, ['text' => 'hi', 'confirm' => true]],
    'like_reply' => [LikeReplyTool::class, ['liked' => true]],
    'archive_reply' => [ArchiveReplyTool::class, []],
    'mark_replies_read' => [MarkRepliesReadTool::class, []],
    'delete_reply' => [DeleteReplyTool::class, ['confirm' => true]],
]);

test('list_replies and mark-all-read never include another workspace\'s replies', function (): void {
    $foreignWorkspace = Workspace::factory()->create();
    $foreignTarget = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $foreignWorkspace->id]))
        ->create(['platform' => Platform::X, 'remote_id' => '700']);
    $foreign = mcpInboundReply($foreignTarget, '701', 'Foreign secret');

    ShoutrrrServer::actingAs($this->user)->tool(ListRepliesTool::class, [])
        ->assertOk()->assertDontSee('Foreign secret');

    ShoutrrrServer::actingAs($this->user)->tool(MarkRepliesReadTool::class, ['all' => true])->assertOk();

    expect(PostTargetReply::withoutGlobalScopes()->find($foreign->id)->read_at)->toBeNull();
});

test('a user removed from the workspace is denied', function (): void {
    WorkspaceMembership::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('user_id', $this->user->id)
        ->delete();

    ShoutrrrServer::actingAs($this->user->fresh())->tool(ArchiveReplyTool::class, ['reply_id' => $this->reply->id])
        ->assertHasErrors(['not bound to a workspace']);

    expect($this->reply->fresh()->status)->toBe(ReplyStatus::Pending);
});

test('engagement tools are refused when engagement is off on the instance', function (): void {
    config()->set('engagement.enabled', false);

    ShoutrrrServer::actingAs($this->user)->tool(ListRepliesTool::class, [])
        ->assertHasErrors(['Engagement is turned off']);
});
