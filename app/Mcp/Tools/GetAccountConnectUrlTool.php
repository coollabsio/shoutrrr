<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_account_connect_url')]
#[Description('Return the browser URL that starts connecting a platform (X, LinkedIn, Bluesky, Meta, Threads, Discord, LinkedIn Pages). The human opens it and finishes OAuth or pastes a Discord webhook there. This tool does not complete the connection.')]
class GetAccountConnectUrlTool extends WorkspaceTool
{
    public function handle(Request $request, AccountConnectLinks $links): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', ConnectedAccount::class)) {
            return $denied;
        }

        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:32'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'pds_url' => ['nullable', 'url', 'max:255'],
        ]);

        $link = $links->describe($validated['platform'], [
            'identifier' => $validated['identifier'] ?? null,
            'pds_url' => $validated['pds_url'] ?? null,
        ]);

        if (! $link['available'] || $link['url'] === null) {
            return Response::error($link['reason'] ?? 'That platform cannot be connected on this instance.');
        }

        return Response::text(json_encode($link, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->description('Platform key from list_platforms: x, linkedin, bluesky, facebook, instagram, threads, discord, or linkedin-pages.')->required(),
            'identifier' => $schema->string()->description('Optional Bluesky handle to prefill the OAuth start URL.'),
            'pds_url' => $schema->string()->description('Optional Bluesky PDS URL when the account is not on bsky.social.'),
        ];
    }
}
