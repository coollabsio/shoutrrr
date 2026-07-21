<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\McpGrantWorkspace;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Api\ApiKeyManager;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Publishing\PublishConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
 * Issue a real key and return [User, Workspace, plaintextToken]. The user is a
 * member of the workspace.
 *
 * @return array{0: User, 1: Workspace, 2: string}
 */
function issuedKey(string $scope = 'write'): array
{
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys', ['--no-interaction' => true]);
    }

    Client::factory()->asPersonalAccessTokenClient()->create(['provider' => 'users']);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => 'admin']);

    [, $plain] = app(ApiKeyManager::class)->issue($workspace, $user, 'test', $scope, null);

    return [$user, $workspace, $plain];
}

/**
 * Build a 4x4 transparent PNG as raw bytes for media-conversion tests.
 */
function transparentPng(int $width = 4, int $height = 4): string
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Create a pending X PostTarget (with account + secret) ready to publish.
 *
 * @param  array<int, string>  $segments
 */
function publishTarget(array $segments = ['hello'], string $status = 'pending'): PostTarget
{
    $post = Post::factory()->create(['status' => PostStatus::Publishing]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'sections' => $segments,
        'status' => $status,
    ]);
}

/**
 * Swap the publish connector registry for a stub returning the given result
 * (or invoking the given callable with the PublishContext).
 */
function bindConnector(PublishResult|callable $result): void
{
    $connector = new class($result) implements PublishConnector
    {
        public function __construct(private $result) {}

        public function publish(PublishContext $context): PublishResult
        {
            return is_callable($this->result) ? ($this->result)($context) : $this->result;
        }

        public function delete(PostTarget $target, array $credentials): void {}
    };

    app()->instance(PublishConnectorRegistry::class, new class($connector) extends PublishConnectorRegistry
    {
        public function __construct(private PublishConnector $connector) {}

        public function for(Platform $platform): PublishConnector
        {
            return $this->connector;
        }
    });
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
