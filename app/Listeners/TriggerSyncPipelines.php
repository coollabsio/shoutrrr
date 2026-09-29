<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PostTargetPublished;
use App\Services\Sync\SyncFanOutService;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerSyncPipelines implements ShouldQueue
{
    public function __construct(private readonly SyncFanOutService $fanOut) {}

    public function handle(PostTargetPublished $event): void
    {
        $this->fanOut->fanOut($event->target);
    }
}
