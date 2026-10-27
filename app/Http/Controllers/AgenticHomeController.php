<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AgentIntentAction;
use App\Models\AgentIntent;
use App\Models\SyncPipeline;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Onboarding\OnboardingPresenter;
use App\Support\UsageOperation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgenticHomeController extends Controller
{
    /**
     * Agentic home: accounts, MCP and API setup, recent agent activity, and sync.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : null;

        return Inertia::render('agentic/home', [
            'onboarding' => $workspace instanceof Workspace
                ? OnboardingPresenter::make($workspace, $user)
                : null,
            'mcp' => [
                'endpoint' => url('/mcp'),
                'docs_url' => 'https://shoutrrr.com/docs/mcp',
                'snippet' => json_encode([
                    'mcpServers' => [
                        'shoutrrr' => [
                            'url' => url('/mcp'),
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ],
            'api' => [
                'base_url' => url('/api/v1'),
                'publish_example' => $this->publishExample(),
                'schedule_example' => $this->scheduleExample(),
            ],
            'sync' => $this->syncSummary($workspaceId),
            'activity' => $workspaceId === null ? [] : $this->activity($workspaceId),
        ]);
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
                'label' => $intent->action === AgentIntentAction::ScheduleAt ? 'Scheduled a post' : 'Publish now',
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
                'label' => 'MCP request',
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
