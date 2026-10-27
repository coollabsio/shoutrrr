<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use App\Support\Notifications\NotificationPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_notifications')]
#[Description('List your notifications for this workspace, newest first: publish failures and successes, account problems, invitations, and similar events. Includes account-wide notifications that belong to no workspace. Returns the items and the total unread count.')]
class ListNotificationsTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $base = $user->notifications()->where(function ($query) use ($workspaceId): void {
            $query->where('data->workspace_id', $workspaceId)
                ->orWhereNull('data->workspace_id');
        });

        $items = (clone $base)
            ->when($validated['unread_only'] ?? false, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? 20)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                ...NotificationPresenter::item($notification),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])
            ->all();

        return Response::text(json_encode([
            'unread_count' => (clone $base)->whereNull('read_at')->count(),
            'notifications' => $items,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'unread_only' => $schema->boolean()->description('Only return unread notifications. Defaults to false.'),
            'limit' => $schema->integer()->description('Maximum notifications to return, 1 to 50. Defaults to 20.')->min(1)->max(50),
        ];
    }
}
