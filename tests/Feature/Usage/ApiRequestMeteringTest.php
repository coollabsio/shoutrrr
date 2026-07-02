<?php

use App\Http\Middleware\RecordApiUsage;
use App\Models\McpGrantWorkspace;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{user_id: string, client_id: string, access_token_id: string, workspace_id: string}
 */
function mcpGrantAttributes(Workspace $workspace, string $tokenId): array
{
    return [
        'user_id' => User::factory()->create()->id,
        'client_id' => 'test-client-id',
        'access_token_id' => $tokenId,
        'workspace_id' => $workspace->id,
    ];
}

/**
 * Mirror what Passport's guard sets: an AccessToken exposing oauth_access_token_id
 * (the Eloquent Token model is never what $user->currentAccessToken() returns).
 */
function mcpUserWithToken(?string $tokenId): object
{
    return new class($tokenId)
    {
        public function __construct(private ?string $tokenId) {}

        public function currentAccessToken(): ?AccessToken
        {
            return $this->tokenId === null
                ? null
                : new AccessToken(['oauth_access_token_id' => $this->tokenId]);
        }
    };
}

it('records an mcp_request for a token bound to a workspace', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $tokenId = 'tok-123';
    McpGrantWorkspace::query()->create(mcpGrantAttributes($workspace, $tokenId));

    $request = Request::create('/mcp', 'POST');
    $request->setUserResolver(fn () => mcpUserWithToken($tokenId));

    app(RecordApiUsage::class)->terminate($request, new Response('', 200));

    expect(UsageEvent::where('operation', 'mcp_request')->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('skips recording when the token has no workspace binding', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $request = Request::create('/mcp', 'POST');
    $request->setUserResolver(fn () => mcpUserWithToken('unbound'));

    app(RecordApiUsage::class)->terminate($request, new Response('', 200));

    expect(UsageEvent::count())->toBe(0);
});

it('skips recording when usage tracking is disabled', function () {
    $workspace = Workspace::factory()->create();
    $tokenId = 'tok-456';
    McpGrantWorkspace::query()->create(mcpGrantAttributes($workspace, $tokenId));

    $request = Request::create('/mcp', 'POST');
    $request->setUserResolver(fn () => mcpUserWithToken($tokenId));

    app(RecordApiUsage::class)->terminate($request, new Response('', 200));

    expect(UsageEvent::count())->toBe(0);
});
