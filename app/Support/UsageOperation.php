<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Controlled vocabulary of metered operations. A plain constant holder (not a
 * DB enum) so adding an operation is a code change with no migration.
 */
final class UsageOperation
{
    public const string POST = 'post';

    public const string DELETE = 'delete';

    public const string METRICS_FETCH_POST = 'metrics_fetch_post';

    public const string METRICS_FETCH_ACCOUNT = 'metrics_fetch_account';

    public const string REPLIES_FETCH = 'replies_fetch';

    public const string REPLY_SEND = 'reply_send';

    public const string REPLY_LIKE = 'reply_like';

    public const string REPLY_UNLIKE = 'reply_unlike';

    public const string REPLY_DELETE = 'reply_delete';

    public const string TOKEN_REFRESH = 'token_refresh';

    public const string MCP_REQUEST = 'mcp_request';

    public const string MEDIA_UPLOAD = 'media_upload';

    public const string MEDIA_STATUS_POLL = 'media_status_poll';
}
