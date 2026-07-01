<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UsageCategory;
use App\Models\McpGrantWorkspace;
use App\Services\Usage\UsageRecorder;
use App\Support\UsageOperation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordApiUsage
{
    public function __construct(private readonly UsageRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Record after the response is sent, off the request's latency path.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        $tokenId = $user?->token()?->id;

        if ($tokenId === null) {
            return;
        }

        $workspaceId = McpGrantWorkspace::query()
            ->where('access_token_id', $tokenId)
            ->value('workspace_id');

        if ($workspaceId === null) {
            return;
        }

        $this->recorder->record(
            category: UsageCategory::ApiRequest,
            operation: UsageOperation::MCP_REQUEST,
            workspaceId: (string) $workspaceId,
            succeeded: $response->getStatusCode() < 400,
            meta: ['status' => $response->getStatusCode()],
        );
    }
}
