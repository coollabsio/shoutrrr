<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Conversation;
use App\Support\InstanceSettings;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * The gates the Classic inbox routes apply (`messages.enabled` plus the
 * workspace-scoped `conversation` binding), for the direct-message tools.
 * Classic has no per-role check on messaging, so neither do these tools.
 *
 * @phpstan-require-extends WorkspaceTool
 */
trait OpensInbox
{
    protected function openInbox(Request $request): ?Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if (! app(InstanceSettings::class)->messagesEnabled()) {
            return Response::error('Direct messages are disabled on this instance.');
        }

        return null;
    }

    protected function openConversation(Request $request): Conversation|Response
    {
        if ($closed = $this->openInbox($request)) {
            return $closed;
        }

        $validated = $request->validate([
            'conversation_id' => ['required', 'string'],
        ]);

        return Conversation::query()->whereKey($validated['conversation_id'])->first()
            ?? Response::error('No conversation with that id exists in this workspace.');
    }
}
