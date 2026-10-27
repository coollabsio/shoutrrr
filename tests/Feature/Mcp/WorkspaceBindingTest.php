<?php

use App\Http\Middleware\CaptureMcpWorkspaceSelection;
use App\Listeners\BindWorkspaceToAccessToken;
use App\Models\McpGrantWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\AccessTokenRevoked;

test('a grant binding can be created and resolved by token id', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $binding = McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => 'client-123',
        'workspace_id' => $workspace->id,
        'access_token_id' => 'token-abc',
    ]);

    $resolved = McpGrantWorkspace::where('access_token_id', 'token-abc')->first();

    expect($resolved->workspace_id)->toBe($workspace->id);
    expect($binding->workspace->id)->toBe($workspace->id);
});

test('consent capture records a pending binding for a member workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    $request = Request::create('/oauth/authorize', 'POST', [
        'workspace_id' => $workspace->id,
        'client_id' => 'client-xyz',
    ]);
    $request->setUserResolver(fn () => $user);

    (new CaptureMcpWorkspaceSelection)->handle($request, fn ($r) => response('ok'));

    expect(McpGrantWorkspace::where('user_id', $user->id)
        ->where('client_id', 'client-xyz')
        ->whereNull('access_token_id')
        ->value('workspace_id'))->toBe($workspace->id);
});

test('consent capture ignores a workspace the user does not belong to', function (): void {
    $user = User::factory()->create();
    $foreign = Workspace::factory()->create();

    $request = Request::create('/oauth/authorize', 'POST', [
        'workspace_id' => $foreign->id,
        'client_id' => 'client-xyz',
    ]);
    $request->setUserResolver(fn () => $user);

    (new CaptureMcpWorkspaceSelection)->handle($request, fn ($r) => response('ok'));

    expect(McpGrantWorkspace::count())->toBe(0);
});

test('issuing a token stamps the pending binding with the token id', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => 'client-xyz',
        'workspace_id' => $workspace->id,
        'access_token_id' => null,
    ]);

    (new BindWorkspaceToAccessToken)->handle(
        new AccessTokenCreated('token-final', $user->id, 'client-xyz'),
    );

    expect(McpGrantWorkspace::where('access_token_id', 'token-final')->value('workspace_id'))
        ->toBe($workspace->id);
});

test('a refresh does not claim a pending binding from a newer consent', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => 'client-xyz',
        'workspace_id' => $workspace->id,
        'access_token_id' => null,
    ]);

    $listener = new BindWorkspaceToAccessToken;
    $listener->handleRevoked(new AccessTokenRevoked('token-old'));
    $listener->handle(new AccessTokenCreated('token-refreshed', $user->id, 'client-xyz'));

    expect(McpGrantWorkspace::whereNull('access_token_id')->count())->toBe(1)
        ->and(McpGrantWorkspace::where('access_token_id', 'token-refreshed')->exists())->toBeFalse();
});
