<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\ConnectedAccount;
use App\Models\Conversation;
use Carbon\CarbonImmutable;

class MessageFetchCadence
{
    public function isDue(ConnectedAccount $account, CarbonImmutable $now): bool
    {
        $lastSynced = Conversation::withoutGlobalScopes()
            ->where('connected_account_id', $account->id)
            ->max('last_synced_at');

        if ($lastSynced === null) {
            return true;
        }

        $floor = (int) config("messages.poll_interval_minutes.{$account->platform->value}", 15);

        return CarbonImmutable::parse($lastSynced)->addMinutes($floor)->lte($now);
    }
}
