<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\McpGrantWorkspace;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgenticSettingsController extends Controller
{
    /**
     * How to connect an AI assistant over MCP, and the REST API for scripts.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = $user->current_workspace_id;

        return Inertia::render('settings/agentic', [
            'mcp' => [
                'endpoint' => url('/mcp'),
                'docs_url' => 'https://shoutrrr.com/docs/mcp',
            ],
            'api' => [
                'base_url' => url('/api/v1'),
                'publish_example' => $this->publishExample(),
                'schedule_example' => $this->scheduleExample(),
            ],
            'agent_connected' => $workspaceId !== null
                && McpGrantWorkspace::query()->where('workspace_id', $workspaceId)->exists(),
        ]);
    }

    private function publishExample(): string
    {
        return <<<'TXT'
POST /api/v1/intents/publish-now
Idempotency-Key: <unique-key>
Authorization: Bearer <api-key>
Content-Type: application/json

{"text":"Hello from an agent","destination":{"kind":"all"}}
TXT;
    }

    private function scheduleExample(): string
    {
        return <<<'TXT'
POST /api/v1/intents/schedule-at
Idempotency-Key: <unique-key>
Authorization: Bearer <api-key>
Content-Type: application/json

{"text":"Hello later","destination":{"kind":"all"},"scheduled_at":"2026-11-01T15:00:00Z"}
TXT;
    }
}
