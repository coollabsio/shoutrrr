<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Gifs\KlipyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports a share back to Klipy so it can rank the item in the user's
 * recents.
 */
class TriggerKlipyShare implements ShouldQueue
{
    use Queueable;

    /**
     * A failed courtesy ping is not worth a retry — the user's attach has
     * already succeeded by the time this job runs.
     */
    public int $tries = 1;

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

    public function handle(KlipyClient $klipy): void
    {
        try {
            $klipy->share($this->catalog, $this->slug, $this->customerId);
        } catch (Throwable $e) {
            // The user's attach already succeeded; a failed courtesy ping to
            // Klipy is not worth a retry or an alert. KlipyClient::share()
            // never exposes the raw request URL (and thus the API key) in an
            // exception message, so this is safe to log as-is.
            Log::info('Klipy share trigger failed', ['slug' => $this->slug, 'error' => $e->getMessage()]);
        }
    }
}
