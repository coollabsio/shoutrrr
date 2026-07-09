<?php

use App\Dto\Engagement\ReplyPostResult;
use App\Enums\Platform;
use App\Enums\ReplyStatus;
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

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $this->actingAs($this->user);

    // Use Platform::X so TokenManager::fresh() returns the stored access_token with NO live HTTP.
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
        'remote_cid' => null,
        'status' => ReplyStatus::Pending,
    ]);
});

function fakePostReply(ReplyPostResult $result): void
{
    $connector = Mockery::mock(EngagementConnector::class);
    $connector->shouldReceive('postReply')->andReturn($result);
    $registry = Mockery::mock(EngagementConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);
    app()->instance(EngagementConnectorRegistry::class, $registry);
}

test('responding posts the reply and records our row', function (): void {
    fakePostReply(ReplyPostResult::ok('at://mine', 'cidmine'));

    $this->post(route('engagement.respond', $this->reply), ['text' => 'thank you!'])
        ->assertRedirect();

    expect($this->reply->fresh()->status)->toBe(ReplyStatus::Responded);
    expect($this->reply->fresh()->our_reply_remote_id)->toBe('at://mine');
    expect(PostTargetReply::withoutGlobalScopes()->where('is_ours', true)->where('remote_reply_id', 'at://mine')->exists())->toBeTrue();
});

test('our outgoing reply joins the conversation of the reply it answers', function (): void {
    fakePostReply(ReplyPostResult::ok('at://mine', 'cidmine'));

    $this->reply->forceFill(['conversation_remote_id' => 'at://base'])->save();

    $this->post(route('engagement.respond', $this->reply), ['text' => 'thank you!'])
        ->assertRedirect();

    $ourRow = PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'at://mine')->firstOrFail();

    expect($ourRow->conversation_remote_id)->toBe('at://base');
});

test('our outgoing reply starts the conversation when it answers a base reply', function (): void {
    fakePostReply(ReplyPostResult::ok('at://mine', 'cidmine'));

    $this->reply->forceFill(['conversation_remote_id' => null])->save();

    $this->post(route('engagement.respond', $this->reply), ['text' => 'thank you!'])
        ->assertRedirect();

    $ourRow = PostTargetReply::withoutGlobalScopes()->where('remote_reply_id', 'at://mine')->firstOrFail();

    expect($ourRow->conversation_remote_id)->toBe($this->reply->remote_reply_id);
});

test('responding rejects over-length text', function (): void {
    fakePostReply(ReplyPostResult::ok('at://mine'));

    $this->post(route('engagement.respond', $this->reply), ['text' => str_repeat('x', 5000)])
        ->assertSessionHasErrors('text');
});

test('replying up to account capability limit is accepted', function (): void {
    // Create a premium account with a higher max_text_length capability
    $premiumAccount = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['max_text_length' => 25000],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $premiumAccount->id,
        'access_token' => 'tok-premium',
    ]);

    $target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))
        ->for($premiumAccount, 'account')
        ->create(['platform' => Platform::X, 'remote_id' => '501']);

    $reply = PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'remote_reply_id' => '901',
        'remote_cid' => null,
        'status' => ReplyStatus::Pending,
    ]);

    fakePostReply(ReplyPostResult::ok('id-premium'));

    // A ~1000-char text exceeds X default (280) but is within the capability (25000)
    $this->post(route('engagement.respond', $reply), ['text' => str_repeat('a', 1000)])
        ->assertSessionHasNoErrors();
});

test('a failed post surfaces an error and does not mark responded', function (): void {
    fakePostReply(ReplyPostResult::failed('platform down'));

    $this->post(route('engagement.respond', $this->reply), ['text' => 'hi'])
        ->assertSessionHas('error');

    expect($this->reply->fresh()->status)->toBe(ReplyStatus::Pending);
});
