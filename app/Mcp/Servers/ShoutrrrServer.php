<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddPostMediaTool;
use App\Mcp\Tools\CreateAccountSetTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\DeleteAccountSetTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\DeleteShareTool;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListAccountSetsTool;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListSharesTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\PublishNowTool;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\ScheduleAtTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\UpdateAccountSetTool;
use App\Mcp\Tools\UpdatePostTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Shoutrrr')]
#[Version('1.0.0')]
#[Instructions('Read and manage social posts, schedules, and connected accounts for one workspace. The workspace is fixed at connection time. Use list_workspaces to see which workspace this connection operates on; reconnect to switch. Prefer publish_now and schedule_at to create and publish or schedule in one idempotent step (pass a stable idempotency_key). Write tools also let you create and edit drafts, schedule an existing post, manage media and account sets, and share links. Irreversible outward-facing actions (publish_now, publish_post_now, retry_post_target, delete_post) require explicit human confirmation — call them with confirm=true only after the human approves.')]
class ShoutrrrServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        GetPostTool::class,
        ListWorkspacesTool::class,
        ListPostsTool::class,
        GetCalendarTool::class,
        ListConnectedAccountsTool::class,
        ListAccountSetsTool::class,
        GetPostingScheduleTool::class,
        CreatePostTool::class,
        PublishNowTool::class,
        ScheduleAtTool::class,
        UpdatePostTool::class,
        SchedulePostTool::class,
        QueuePostTool::class,
        AddPostMediaTool::class,
        RemovePostMediaTool::class,
        CreateAccountSetTool::class,
        UpdateAccountSetTool::class,
        DeleteAccountSetTool::class,
        CreateShareLinkTool::class,
        ListSharesTool::class,
        DeleteShareTool::class,
        PublishPostTool::class,
        RetryPostTargetTool::class,
        DeletePostTool::class,
    ];
}
