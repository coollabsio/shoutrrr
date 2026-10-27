<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\McpGrantWorkspace;
use Illuminate\Support\Facades\Context;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\AccessTokenRevoked;

/**
 * Keeps the token<->workspace binding on the current access token. A code exchange
 * stamps the pending consent row; a refresh moves the binding from the old token
 * (revoked earlier in the same request) to the new one.
 */
class BindWorkspaceToAccessToken
{
    private const string REVOKED_TOKEN_KEY = 'mcp.revoked_access_token_id';

    public function handleRevoked(AccessTokenRevoked $event): void
    {
        Context::addHidden(self::REVOKED_TOKEN_KEY, $event->tokenId);
    }

    public function handle(AccessTokenCreated $event): void
    {
        $revokedTokenId = Context::pullHidden(self::REVOKED_TOKEN_KEY);

        // Refresh: never claim a pending row, it belongs to a newer consent.
        if (is_string($revokedTokenId)) {
            McpGrantWorkspace::query()
                ->where('access_token_id', $revokedTokenId)
                ->where('client_id', $event->clientId)
                ->update(['access_token_id' => $event->tokenId]);

            return;
        }

        // SQLite does not support UPDATE … LIMIT, so we locate the pending row by
        // primary key first and then update by id.
        $pending = McpGrantWorkspace::query()
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->whereNull('access_token_id')
            ->latest()
            ->value('id');

        if ($pending === null) {
            return;
        }

        McpGrantWorkspace::where('id', $pending)
            ->update(['access_token_id' => $event->tokenId]);
    }
}
