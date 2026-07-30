<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Jobs\FetchAccountMessages;
use App\Models\ConnectedAccount;
use App\Services\Messaging\MessageFetchCadence;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchDueMessageFetches extends Command
{
    protected $signature = 'messages:dispatch-due';

    protected $description = 'Fan out DM-fetch jobs for consented connected accounts that are due.';

    public function handle(InstanceSettings $settings, MessageFetchCadence $cadence): int
    {
        if (! $settings->messagesEnabled()) {
            return self::SUCCESS;
        }

        $supported = array_map(
            fn (Platform $platform): string => $platform->value,
            array_filter(Platform::cases(), fn (Platform $platform): bool => $platform->supportsDirectMessages()),
        );

        $now = CarbonImmutable::now();

        ConnectedAccount::withoutGlobalScopes()
            ->whereIn('platform', $supported)
            ->whereNull('disabled_at')
            ->where('status', ConnectedAccountStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('messaging_rate_limited_until')->orWhere('messaging_rate_limited_until', '<=', $now))
            ->get()
            ->filter(fn (ConnectedAccount $a) => $a->canReceiveDirectMessages())
            ->filter(fn (ConnectedAccount $a) => $cadence->isDue($a, $now))
            ->each(fn (ConnectedAccount $a) => FetchAccountMessages::dispatch($a));

        return self::SUCCESS;
    }
}
