<?php

use App\Enums\WorkspaceRole;
use App\Models\McpGrantWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

ini_set('memory_limit', '512M');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a Passport access token bound to the given workspace and install it
 * on the user so that WorkspaceTool::workspaceId() can resolve it via token().
 *
 * We create the Token model directly (bypassing the OAuth flow) because the
 * full personal-access grant requires a running authorization server. We then
 * wrap it in an AccessToken (the ScopeAuthorizable that withAccessToken expects),
 * with 'oauth_access_token_id' set so AccessToken::__get('id') proxies to the
 * underlying Token id, which is what WorkspaceTool::workspaceId() reads.
 */
function bindTokenToWorkspace(User $user, Workspace $workspace): void
{
    // A real MCP grant is only ever issued for a workspace the user belongs to
    // (CaptureMcpWorkspaceSelection verifies membership at consent), so model that.
    WorkspaceMembership::query()->firstOrCreate(
        ['workspace_id' => $workspace->id, 'user_id' => $user->id],
        ['role' => WorkspaceRole::Member],
    );

    $client = Client::factory()->asPersonalAccessTokenClient()->create([
        'provider' => 'users',
        'secret' => Str::random(40),
    ]);

    $tokenId = Str::random(80);

    $token = Passport::token()->forceFill([
        'id' => $tokenId,
        'user_id' => $user->id,
        'client_id' => $client->id,
        'name' => 'mcp-test',
        'scopes' => [],
        'revoked' => false,
        'expires_at' => now()->addYear(),
    ]);
    $token->save();

    McpGrantWorkspace::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'workspace_id' => $workspace->id,
        'access_token_id' => $tokenId,
    ]);

    // AccessToken wraps the underlying Token and implements ScopeAuthorizable.
    // Setting oauth_access_token_id allows AccessToken::__get('id') to proxy
    // to the Token model's primary key, which is what WorkspaceTool::workspaceId()
    // reads via $request->user()->token()->id.
    $accessToken = new AccessToken(['oauth_access_token_id' => $tokenId]);

    // actingAs() stores the same $user instance on the guard, so token() remains
    // set when the tool resolves $request->user() from the auth guard.
    $user->withAccessToken($accessToken);
}
