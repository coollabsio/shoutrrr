<?php

use App\Http\Middleware\RecordApiUsage;
use App\Models\McpGrantWorkspace;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function mcpGrantAttributes(Workspace $workspace, string $tokenId): array
{
    return [
        'user_id' => User::factory()->create()->id,
        'client_id' => 'test-client-id',
        'access_token_id' => $tokenId,
        'workspace_id' => $workspace->id,
    ];
}

it('records an mcp_request for a token bound to a workspace', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $tokenId = 'tok-123';
    McpGrantWorkspace::query()->create(mcpGrantAttributes($workspace, $tokenId));

    $request = Request::create('/mcp', 'POST');
    $request->setUserResolver(fn () => new class($tokenId)
    {
        public function __construct(private string $id) {}

        public function token(): object
        {
            return (object) ['id' => $this->id];
        }
    });

    app(RecordApiUsage::class)->terminate($request, new Response('', 200));

    expect(UsageEvent::where('operation', 'mcp_request')->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('skips recording when the token has no workspace binding', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $request = Request::create('/mcp', 'POST');
    $request->setUserResolver(fn () => new class
    {
        public function token(): object
        {
            return (object) ['id' => 'unbound'];
        }
    });

    app(RecordApiUsage::class)->terminate($request, new Response('', 200));

    expect(UsageEvent::count())->toBe(0);
});
