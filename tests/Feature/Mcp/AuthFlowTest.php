<?php

use App\Http\Middleware\CaptureMcpWorkspaceSelection;
use App\Models\McpGrantWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;

/**
 * The /oauth/authorize endpoint loads Passport's RSA encryption keys, which are
 * gitignored (storage/*.key) and absent on a fresh checkout or CI runner. Generate
 * them when missing so this flow test is hermetic; existing local keys are left
 * untouched (no --force).
 */
beforeEach(function (): void {
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }
});

/**
 * End-to-end OAuth consent flow:
 *
 * GET /oauth/authorize  — verifies our authorizationView closure renders the
 *                         custom consent blade (workspace picker visible) and
 *                         seeds the session authToken.
 *
 * POST /oauth/authorize — verifies CaptureMcpWorkspaceSelection (appended to the
 *                         web middleware group) runs, persists the pending
 *                         McpGrantWorkspace binding, and that the approve
 *                         endpoint completes the authorization (302 to callback).
 *
 * We use a *confidential* client so PKCE is not required (League OAuth2 Server
 * only mandates code_challenge for public clients). The client is still owned by
 * the user so firstParty() returns false and skipsAuthorization() returns false,
 * meaning Passport always renders the consent view.
 */
test('approving consent with a workspace binds it (pending) for the issued grant', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    // createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false)
    // Use confidential:true (default) so PKCE is not enforced on the authorize
    // endpoint — League OAuth2 only requires code_challenge for public clients.
    // Passing $user sets owner_id/user_id, so firstParty() → false and
    // skipsAuthorization() → false, ensuring the consent view is always shown.
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Test MCP Client',
        ['http://localhost/callback'],
        confidential: true,
        user: $user,
    );

    $this->actingAs($user);

    $query = http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'http://localhost/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'xyz',
    ]);

    // GET consent — our Passport::authorizationView() closure renders
    // resources/views/oauth/authorize.blade.php with the workspace picker.
    // Asserting $workspace->name proves the closure correctly injected the
    // user's workspaces into the view.
    $this->get("/oauth/authorize?{$query}")
        ->assertOk()
        ->assertSee($workspace->name);

    // POST approve — auth_token comes from the session set during the GET.
    // RetrievesAuthRequestFromSession pulls 'authToken' from the session and
    // compares it against the posted auth_token field.
    // A 302 redirect to the callback is the expected success response.
    $this->post('/oauth/authorize', [
        'client_id' => $client->getKey(),
        'workspace_id' => $workspace->id,
        'auth_token' => session('authToken'),
    ]);

    // CaptureMcpWorkspaceSelection (appended to the web middleware group, with an
    // early-return self-guard for non-approve routes) must have created a pending
    // binding (access_token_id null) for this user + client + workspace.
    expect(McpGrantWorkspace::where('user_id', $user->id)
        ->where('client_id', $client->getKey())
        ->whereNull('access_token_id')
        ->where('workspace_id', $workspace->id)
        ->exists())->toBeTrue();
});

test('the approve route carries the capture middleware via the web group', function (): void {
    $route = Route::getRoutes()->getByName('passport.authorizations.approve');

    expect($route)->not->toBeNull();

    // gatherMiddleware() returns group names like "web", not the expanded FQCN list.
    // We verify (a) the route is in the web group, and (b) the web group contains
    // CaptureMcpWorkspaceSelection — together that proves the middleware will run.
    expect($route->gatherMiddleware())->toContain('web');

    $webGroup = app(Kernel::class)->getMiddlewareGroups()['web'] ?? [];
    expect($webGroup)->toContain(CaptureMcpWorkspaceSelection::class);
});

test('the workspace binding follows the access token through a refresh', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Test MCP Client',
        ['http://localhost/callback'],
        confidential: true,
        user: $user,
    );

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'http://localhost/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'xyz',
    ]))->assertOk();

    $redirect = $this->post('/oauth/authorize', [
        'client_id' => $client->getKey(),
        'workspace_id' => $workspace->id,
        'auth_token' => session('authToken'),
    ])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $callback);

    $credentials = [
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => 'http://localhost/callback',
    ];
    $first = $this->post('/oauth/token', [...$credentials, 'grant_type' => 'authorization_code', 'code' => $callback['code']])
        ->assertOk()->json();
    $refreshed = $this->post('/oauth/token', [...$credentials, 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']])
        ->assertOk()->json();

    $tokenId = fn (array $response): string => json_decode(base64_decode(strtr(explode('.', $response['access_token'])[1], '-_', '+/')), true)['jti'];

    expect($tokenId($refreshed))->not->toBe($tokenId($first))
        ->and(McpGrantWorkspace::where('access_token_id', $tokenId($refreshed))->value('workspace_id'))->toBe($workspace->id)
        ->and(McpGrantWorkspace::count())->toBe(1);
});
