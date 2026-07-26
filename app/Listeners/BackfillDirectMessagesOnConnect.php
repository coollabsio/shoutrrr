<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ConnectedAccountConnected;
use App\Jobs\FetchAccountMessages;
use App\Support\InstanceSettings;

/**
 * Pull an account's DMs as soon as it connects, instead of leaving the inbox
 * empty until the next scheduled sweep (up to 15 minutes later).
 *
 * This also covers the re-auth path: an existing account that reconnects to
 * grant DM scopes fires the same event, so its history lands immediately.
 */
class BackfillDirectMessagesOnConnect
{
    public function __construct(private readonly InstanceSettings $settings) {}

    public function handle(ConnectedAccountConnected $event): void
    {
        if (! $this->settings->messagesEnabled()) {
            return;
        }

        // Platforms without a DM API, accounts the user never granted DM
        // access to, and disabled accounts all have nothing to fetch.
        if ($event->account->isDisabled() || ! $event->account->canReceiveDirectMessages()) {
            return;
        }

        // Bypasses MessageFetchCadence deliberately: connecting is an explicit
        // user action, and a fresh account has never synced anyway. The job is
        // ShouldBeUnique, so a reconnect storm still collapses to one fetch.
        FetchAccountMessages::dispatch($event->account);
    }
}
