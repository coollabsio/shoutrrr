<?php

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
use App\Support\InstanceSettings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

function makeReplyForUser(): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    $target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $workspace->id]))
        ->for($account, 'account')
        ->create(['platform' => Platform::X, 'remote_id' => '500']);

    $reply = PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'remote_reply_id' => '900',
        'remote_cid' => null,
        'status' => ReplyStatus::Pending,
        'is_ours' => false,
        'author_handle' => 'testuser',
        'text' => 'Great post!',
    ]);

    return [$user, $reply];
}

it('404s when ai disabled', function () {
    [$user, $reply] = makeReplyForUser();

    $this->actingAs($user)
        ->post("/ai/engagement/{$reply->id}/suggest", ['tone' => 'friendly'])
        ->assertNotFound();
});

it('streams a reply suggestion', function () {
    app(InstanceSettings::class)->updateAi(
        ['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'],
        'sk-test',
    );
    Prism::fake([TextResponseFake::make()->withText('Thanks so much!')])->withFakeChunkSize(100);

    [$user, $reply] = makeReplyForUser();

    $response = $this->actingAs($user)
        ->post("/ai/engagement/{$reply->id}/suggest", ['tone' => 'friendly']);

    $response->assertOk();
    expect($response->streamedContent())->toContain('Thanks so much!');
    expect($response->streamedContent())->toContain('"type":"delta"');
});
