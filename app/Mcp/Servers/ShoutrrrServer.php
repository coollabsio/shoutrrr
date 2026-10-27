<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddPostMediaTool;
use App\Mcp\Tools\AddPostVideoTool;
use App\Mcp\Tools\ArchiveConversationTool;
use App\Mcp\Tools\ArchiveReplyTool;
use App\Mcp\Tools\AttachGifTool;
use App\Mcp\Tools\CancelInvitationTool;
use App\Mcp\Tools\CreateAccountSetTool;
use App\Mcp\Tools\CreateMentionTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\CreateSyncPipelineTool;
use App\Mcp\Tools\DeleteAccountSetTool;
use App\Mcp\Tools\DeleteMentionTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\DeleteReplyTool;
use App\Mcp\Tools\DeleteShareTool;
use App\Mcp\Tools\DeleteSyncPipelineTool;
use App\Mcp\Tools\DisconnectAccountTool;
use App\Mcp\Tools\DuplicatePostTool;
use App\Mcp\Tools\GetAccountConnectUrlTool;
use App\Mcp\Tools\GetAnalyticsTool;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetConversationTool;
use App\Mcp\Tools\GetNextSlotTool;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\GetReplyThreadTool;
use App\Mcp\Tools\InviteMemberTool;
use App\Mcp\Tools\LikeReplyTool;
use App\Mcp\Tools\ListAccountSetsTool;
use App\Mcp\Tools\ListConnectedAccountsTool;
use App\Mcp\Tools\ListConversationsTool;
use App\Mcp\Tools\ListMembersTool;
use App\Mcp\Tools\ListMentionsTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\ListPlatformsTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListRepliesTool;
use App\Mcp\Tools\ListSharesTool;
use App\Mcp\Tools\ListSyncPipelinesTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\MakeDefaultAccountTool;
use App\Mcp\Tools\MarkConversationReadTool;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Mcp\Tools\MarkRepliesReadTool;
use App\Mcp\Tools\PublishNowTool;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\ReconnectAccountTool;
use App\Mcp\Tools\RefreshPostMetricsTool;
use App\Mcp\Tools\RefreshXTierTool;
use App\Mcp\Tools\RemoveMemberTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Mcp\Tools\ReplyToConversationTool;
use App\Mcp\Tools\RespondToReplyTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\ScheduleAtTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\SearchGifsTool;
use App\Mcp\Tools\SetAutoRepostTool;
use App\Mcp\Tools\SetNativeTrackingTool;
use App\Mcp\Tools\ToggleAccountTool;
use App\Mcp\Tools\UpdateAccountSetTool;
use App\Mcp\Tools\UpdateMediaAltTool;
use App\Mcp\Tools\UpdateMemberRoleTool;
use App\Mcp\Tools\UpdatePostingScheduleTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Mcp\Tools\UpdateSyncPipelineTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Shoutrrr')]
#[Version('1.0.0')]
#[Instructions('Set up and operate one workspace. The workspace is fixed at connection time; list_workspaces shows which one, and reconnecting switches it. Setup path: list_platforms, then get_account_connect_url and give that URL to the human. They finish provider OAuth or paste a Discord webhook in the browser — never complete OAuth or accept a webhook or app password here. Then list_connected_accounts. From there, create account sets, sync pipelines (list/create/update/delete_sync_pipeline), set_native_tracking, and update_posting_schedule. reconnect_account, toggle_account, make_default_account, and set_auto_repost adjust accounts that are already connected. list_members, invite_member, update_member_role, remove_member, and cancel_invitation manage the team; list_mentions and create_mention keep reusable handles. To publish, prefer publish_now and schedule_at with a stable idempotency_key; they accept segments, media_ids, and mentions. add_post_media, add_post_video, attach_gif, and update_media_alt manage media; get_next_slot shows the next queue time. After publishing, use get_analytics, refresh_post_metrics, and list_notifications to see results and failures. Handle replies with list_replies, get_reply_thread, respond_to_reply, like_reply, archive_reply, and mark_replies_read, and direct messages with list_conversations, get_conversation, reply_to_conversation, mark_conversation_read, and archive_conversation. disconnect_account, delete_sync_pipeline, publish_now, publish_post_now, retry_post_target, delete_post, respond_to_reply, delete_reply, reply_to_conversation, invite_member, update_member_role, and remove_member are irreversible or outward-facing and require confirm=true only after the human approves.')]
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
        ListPlatformsTool::class,
        GetAccountConnectUrlTool::class,
        ReconnectAccountTool::class,
        ToggleAccountTool::class,
        MakeDefaultAccountTool::class,
        SetAutoRepostTool::class,
        DisconnectAccountTool::class,
        ListSyncPipelinesTool::class,
        CreateSyncPipelineTool::class,
        UpdateSyncPipelineTool::class,
        DeleteSyncPipelineTool::class,
        SetNativeTrackingTool::class,
        ListAccountSetsTool::class,
        GetPostingScheduleTool::class,
        UpdatePostingScheduleTool::class,
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
        ListRepliesTool::class,
        GetReplyThreadTool::class,
        RespondToReplyTool::class,
        LikeReplyTool::class,
        ArchiveReplyTool::class,
        MarkRepliesReadTool::class,
        DeleteReplyTool::class,
        ListConversationsTool::class,
        GetConversationTool::class,
        ReplyToConversationTool::class,
        MarkConversationReadTool::class,
        ArchiveConversationTool::class,
        GetAnalyticsTool::class,
        RefreshPostMetricsTool::class,
        ListNotificationsTool::class,
        MarkNotificationsReadTool::class,
        AddPostVideoTool::class,
        UpdateMediaAltTool::class,
        SearchGifsTool::class,
        AttachGifTool::class,
        GetNextSlotTool::class,
        DuplicatePostTool::class,
        ListMentionsTool::class,
        CreateMentionTool::class,
        DeleteMentionTool::class,
        ListMembersTool::class,
        InviteMemberTool::class,
        UpdateMemberRoleTool::class,
        RemoveMemberTool::class,
        CancelInvitationTool::class,
        RefreshXTierTool::class,
    ];
}
