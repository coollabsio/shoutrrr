<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UsageCategory;
use App\Models\McpGrantWorkspace;
use App\Services\Usage\UsageRecorder;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Closure;
use Illuminate\Http\Request;
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

            $workspaceId = McpGrantWorkspace::query()
                ->where('access_token_id', $accessToken->oauth_access_token_id)
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
        } catch (Throwable $e) {
            // Metering must never surface from the terminate phase; swallow + report.
            report($e);
        }
    }
}
