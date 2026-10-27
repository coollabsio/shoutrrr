<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mark_notifications_read')]
#[Description('Mark notifications as read. Pass notification_id to mark one, or omit it to mark every unread notification in this workspace as read. Returns how many were marked.')]
class MarkNotificationsReadTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'notification_id' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $notificationId = $validated['notification_id'] ?? null;

        $scoped = $user->notifications()->where(function ($query) use ($workspaceId): void {
            $query->where('data->workspace_id', $workspaceId)
                ->orWhereNull('data->workspace_id');
        });

        if ($notificationId !== null) {
            if (! (clone $scoped)->whereKey($notificationId)->exists()) {
                return Response::error('No notification with that id exists for you in this workspace.');
            }

            $scoped->whereKey($notificationId);
        }

        $marked = $scoped->whereNull('read_at')->update(['read_at' => Date::now()]);

        return Response::text(json_encode(['marked_read' => $marked], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'notification_id' => $schema->string()->description('Id of one notification to mark read. Omit to mark all unread notifications in this workspace.'),
        ];
    }
}
