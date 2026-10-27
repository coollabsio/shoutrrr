<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\PostTargetReply;
use App\Support\InstanceSettings;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Base for the engagement inbox tools. Applies the same gate as the Classic
 * inbox routes (the instance-wide engagement switch) on top of workspace binding.
 */
abstract class EngagementTool extends WorkspaceTool
{
    /**
     * Returns an error Response to return immediately, or null to proceed.
     */
    protected function bindEngagement(Request $request): ?Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if (! app(InstanceSettings::class)->engagementEnabled()) {
            return Response::error('Engagement is turned off on this Shoutrrr instance.');
        }

        return null;
    }

    protected function findReply(string $replyId): ?PostTargetReply
    {
        return PostTargetReply::query()->whereKey($replyId)->with(['target.post', 'target.account'])->first();
    }

    protected function replyNotFound(): Response
    {
        return Response::error('No reply with that id exists in this workspace.');
    }

    protected function actionFailed(?string $message, string $fallback): Response
    {
        return Response::error($message ?? $fallback);
    }
}
