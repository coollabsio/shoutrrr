<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AgentIntentAction;
use App\Models\AgentIntent;
use App\Models\ConnectedAccount;
use App\Models\McpGrantWorkspace;
use App\Models\SyncPipeline;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\UsageOperation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgenticHomeController extends Controller
{
    /**
     * Agentic home: connect an agent over MCP OAuth. The agent does the rest.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = $user->currentWorkspace instanceof Workspace
            ? $user->currentWorkspace->id
            : null;

        return Inertia::render('agentic/home', [
            'mcp' => [
                'endpoint' => url('/mcp'),
                'docs_url' => 'https://shoutrrr.com/docs/mcp',
            ],
            'api' => [
                'base_url' => url('/api/v1'),
                'publish_example' => $this->publishExample(),
                'schedule_example' => $this->scheduleExample(),
            ],
            'setup' => $this->setupProgress($workspaceId),
            'sync' => $this->syncSummary($workspaceId),
            'activity' => $workspaceId === null ? [] : $this->activity($workspaceId),
        ]);
    }

    /**
     * @return array{accounts: int, agent_connected: bool}
     */
    private function setupProgress(?string $workspaceId): array
    {
        if ($workspaceId === null) {
            return ['accounts' => 0, 'agent_connected' => false];
        }

        return [
            'accounts' => ConnectedAccount::query()->where('workspace_id', $workspaceId)->enabled()->count(),
            'agent_connected' => McpGrantWorkspace::query()->where('workspace_id', $workspaceId)->exists(),
        ];
    }

    /**
     * @return array{total: int, enabled: int}
     */
    private function syncSummary(?string $workspaceId): array
    {
        if ($workspaceId === null) {
            return ['total' => 0, 'enabled' => 0];
        }

        $pipelines = SyncPipeline::query()->where('workspace_id', $workspaceId);

        return [
            'total' => (clone $pipelines)->count(),
            'enabled' => (clone $pipelines)->where('enabled', true)->count(),
        ];
    }

    /**
     * @return list<array{id: string, kind: string, label: string, detail: string|null, succeeded: bool|null, occurred_at: string}>
     */
    private function activity(string $workspaceId): array
    {
        /** @var list<array{id: string, kind: string, label: string, detail: string|null, succeeded: bool|null, occurred_at: string, sort: string}> $items */
        $items = [];

        foreach (AgentIntent::query()->where('workspace_id', $workspaceId)->latest()->limit(8)->get() as $intent) {
            $occurredAt = $intent->created_at?->toIso8601String() ?? now()->toIso8601String();
            $items[] = [
                'id' => 'intent-'.$intent->id,
                'kind' => 'intent',
                'label' => $intent->action === AgentIntentAction::ScheduleAt ? 'Scheduled a post' : 'Published a post',
                'detail' => null,
                'succeeded' => null,
                'occurred_at' => $occurredAt,
                'sort' => $occurredAt,
            ];
        }

        foreach (
            UsageEvent::query()
                ->where('workspace_id', $workspaceId)
                ->where('operation', UsageOperation::MCP_REQUEST)
                ->latest('occurred_at')
                ->limit(8)
                ->get() as $event
        ) {
            $occurredAt = $event->occurred_at->toIso8601String();
            $status = is_array($event->meta) ? ($event->meta['status'] ?? null) : null;
            $items[] = [
                'id' => 'mcp-'.$event->id,
                'kind' => 'mcp',
                'label' => 'Assistant request',
                'detail' => is_int($status) || is_string($status) ? 'HTTP '.$status : null,
                'succeeded' => $event->succeeded,
                'occurred_at' => $occurredAt,
                'sort' => $occurredAt,
            ];
        }

        usort($items, fn (array $left, array $right): int => $right['sort'] <=> $left['sort']);

        return array_map(
            fn (array $item): array => [
                'id' => $item['id'],
                'kind' => $item['kind'],
                'label' => $item['label'],
                'detail' => $item['detail'],
                'succeeded' => $item['succeeded'],
                'occurred_at' => $item['occurred_at'],
            ],
            array_slice($items, 0, 8),
        );
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
