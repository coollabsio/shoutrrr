<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reports a share back to Klipy so it can rank the item in the user's
 * recents. Stubbed here so Task 6's controllers resolve; Task 7 fills in
 * handle() and tests the behaviour.
 */
class TriggerKlipyShare implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $catalog,
        public readonly string $slug,
        public readonly string $customerId,
    ) {}

    public static function maybeDispatch(string $catalog, string $slug, string $customerId): void
    {
        if ((bool) config('services.klipy.share_trigger', true)) {
            self::dispatch($catalog, $slug, $customerId);
        }
    }

    public function handle(): void
    {
        // Implemented in Task 7.
    }
}
