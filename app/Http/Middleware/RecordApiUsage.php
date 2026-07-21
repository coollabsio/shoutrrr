<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UsageCategory;
use App\Models\ApiKey;
use App\Models\McpGrantWorkspace;
use App\Services\Usage\UsageRecorder;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordApiUsage
{
    public function __construct(
        private readonly UsageRecorder $recorder,
        private readonly InstanceSettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Record after the response is sent, off the request's latency path.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            // Bail before any work when metering is off (the default), so a disabled
            // instance pays no per-request DB lookup.
            if (! $this->settings->usageTrackingEnabled()) {
                return;
            }

            // Passport's guard exposes the authenticated token as an AccessToken, not
            // the Eloquent Token model — read the id the same way WorkspaceTool does.
            $accessToken = $request->user()?->currentAccessToken();

            if (! $accessToken instanceof AccessToken) {
                return;
            }

            $tokenId = $accessToken->oauth_access_token_id;

            if ($request->is('api/*')) {
                $workspaceId = $this->resolveWorkspaceId($tokenId, true);
                $operation = UsageOperation::API_REQUEST;
            } else {
                $workspaceId = $this->resolveWorkspaceId($tokenId, false);
                $operation = UsageOperation::MCP_REQUEST;
            }

            if ($workspaceId === null) {
                return;
            }

            $this->recorder->record(
                category: UsageCategory::ApiRequest,
                operation: $operation,
                workspaceId: (string) $workspaceId,
                succeeded: $response->getStatusCode() < 400,
                meta: ['status' => $response->getStatusCode()],
            );
        } catch (Throwable $e) {
            // Metering must never surface from the terminate phase; swallow + report.
            report($e);
        }
    }

    /**
     * Map an access-token id to its workspace. The mapping is stable for the
     * token's lifetime, so it is cached; a revoked token stops authenticating
     * upstream regardless, making a stale positive mapping harmless. Unknown
     * tokens return null and are not cached (so a later grant resolves).
     */
    public function resolveWorkspaceId(string $tokenId, bool $isApi): ?string
    {
        $cacheKey = 'usage-workspace:'.($isApi ? 'api' : 'mcp').':'.$tokenId;

        return Cache::remember($cacheKey, now()->addMinutes(60), function () use ($tokenId, $isApi): ?string {
            $query = $isApi
                ? ApiKey::query()
                : McpGrantWorkspace::query();

            $workspaceId = $query->where('access_token_id', $tokenId)->value('workspace_id');

            return $workspaceId === null ? null : (string) $workspaceId;
        });
    }
}
