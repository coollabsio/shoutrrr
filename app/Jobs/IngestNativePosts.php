<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConnectedAccount;
use App\Services\NativeRead\ExternalIngestService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestNativePosts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Keep a second job for the same account from overlapping a still-running
     * poll (slow job + next schedule tick), which would otherwise double-post.
     * Outlives $timeout so the lock never expires under a still-running job.
     */
    public int $uniqueFor = 1200;

    /**
     * Pipeline sources download their videos inside this run. MUST stay below the
     * queue connection's `retry_after` (default 1200), as PublishPostTarget does.
     */
    public int $timeout = 900;

    public function __construct(public ConnectedAccount $account) {}

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    public function handle(ExternalIngestService $ingest): void
    {
        $ingest->ingest($this->account);
    }
}
