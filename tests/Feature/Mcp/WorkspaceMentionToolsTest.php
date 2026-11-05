<?php

use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreateMentionTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\DeleteMentionTool;
use App\Mcp\Tools\ListMentionsTool;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMention;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function mentionToolJson(TestResponse $response): array
{
    $payload = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($payload['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

test('create_mention saves normalized handles and list_mentions returns them with a post_mention entry', function (): void {
    [$user, $workspace] = mcpOwner();

    $created = ShoutrrrServer::actingAs($user)->tool(CreateMentionTool::class, [
        'name' => '@acme.corp',
        'handles' => ['x' => ' acme ', 'bluesky' => 'acme.bsky.social', 'threads' => '', 'linkedin_urn' => 'urn:li:organization:42'],
    ]);
    $created->assertOk();

    $expectedHandles = ['x' => 'acme', 'bluesky' => 'acme.bsky.social', 'linkedin_urn' => 'urn:li:organization:42'];
    $mention = WorkspaceMention::withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole();
    expect($mention->name)->toBe('@acme.corp')
        ->and($mention->handles)->toBe($expectedHandles)
        ->and(mentionToolJson($created)['mention']['id'])->toBe($mention->id);

    $listed = mentionToolJson(ShoutrrrServer::actingAs($user)->tool(ListMentionsTool::class, []));

    expect($listed['mentions'])->toBe([[
        'id' => $mention->id,
        'name' => '@acme.corp',
        'handles' => $expectedHandles,
        'post_mention' => ['id' => 'acme-corp', 'label' => '@acme.corp', 'handles' => $expectedHandles],
    ]]);
});

test('create_mention with an existing name replaces its handles', function (): void {
    [$user, $workspace] = mcpOwner();
    WorkspaceMention::factory()->create(['workspace_id' => $workspace->id, 'name' => '@acme', 'handles' => ['x' => 'old']]);

    ShoutrrrServer::actingAs($user)->tool(CreateMentionTool::class, [
        'name' => '@acme', 'handles' => ['x' => 'new'],
    ])->assertOk();

    expect(WorkspaceMention::withoutGlobalScopes()->where('workspace_id', $workspace->id)->pluck('handles')->all())
        ->toBe([['x' => 'new']]);
});

test('create_mention rejects a name without a leading @', function (): void {
    [$user, $workspace] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(CreateMentionTool::class, [
        'name' => 'acme', 'handles' => ['x' => 'acme'],
    ])->assertHasErrors();

    expect(WorkspaceMention::withoutGlobalScopes()->count())->toBe(0);
});

test('a listed post_mention is accepted by create_post and resolves per platform', function (): void {
    [$user, $workspace] = mcpOwner();
    ConnectedAccount::factory()->for($workspace)->create(['platform' => 'x']);
    WorkspaceMention::factory()->create(['workspace_id' => $workspace->id, 'name' => '@acme', 'handles' => ['x' => '@acme_x']]);

    $postMention = mentionToolJson(ShoutrrrServer::actingAs($user)->tool(ListMentionsTool::class, []))['mentions'][0]['post_mention'];

    $post = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'Hello {{mention:'.$postMention['id'].'}}',
        'mentions' => [$postMention],
        'destination' => ['kind' => 'all'],
    ]);

    $post->assertOk();

    expect(mentionToolJson($post)['targets'][0]['sections'])->toBe(['Hello @acme_x']);
});

test('delete_mention removes a mention in the bound workspace', function (): void {
    [$user, $workspace] = mcpOwner();
    $mention = WorkspaceMention::factory()->create(['workspace_id' => $workspace->id, 'name' => '@acme']);

    ShoutrrrServer::actingAs($user)->tool(DeleteMentionTool::class, ['mention_id' => $mention->id])->assertOk();

    expect(WorkspaceMention::withoutGlobalScopes()->find($mention->id))->toBeNull();
});

test('a plain member can manage mentions, matching Classic', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    ShoutrrrServer::actingAs($user)->tool(CreateMentionTool::class, [
        'name' => '@acme', 'handles' => ['x' => 'acme'],
    ])->assertOk();

    expect(WorkspaceMention::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('mention tools cannot see or delete another workspace\'s mentions', function (): void {
    [$user, $workspace] = mcpOwner();
    $foreign = WorkspaceMention::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'name' => '@foreign']);

    ShoutrrrServer::actingAs($user)->tool(DeleteMentionTool::class, ['mention_id' => $foreign->id])
        ->assertHasErrors(['No saved mention with that id exists in this workspace.']);

    expect(WorkspaceMention::withoutGlobalScopes()->find($foreign->id))->not->toBeNull()
        ->and(mentionToolJson(ShoutrrrServer::actingAs($user)->tool(ListMentionsTool::class, []))['mentions'])->toBe([]);
});
