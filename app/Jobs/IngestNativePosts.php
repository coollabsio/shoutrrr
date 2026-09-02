<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConnectedAccount;
use App\Services\NativeRead\ExternalIngestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestNativePosts implements ShouldQueue
{
    use Queueable;

    public function __construct(public ConnectedAccount $account) {}

    public function handle(ExternalIngestService $ingest): void
    {
        $ingest->ingest($this->account);
    }
}
