<?php

declare(strict_types=1);

use App\Events\PostTargetPublished;
use App\Listeners\TriggerSyncPipelines;
use App\Models\PostTarget;
use App\Services\Sync\SyncFanOutService;
use Illuminate\Support\Facades\Event;

test('the listener forwards the target to the fan-out service', function () {
    $target = Mockery::mock(PostTarget::class);
    $service = Mockery::mock(SyncFanOutService::class);
    $service->shouldReceive('fanOut')->once()->with($target);

    (new TriggerSyncPipelines($service))->handle(new PostTargetPublished($target));
});

test('the event is registered to the sync listener', function () {
    Event::fake();
    Event::assertListening(PostTargetPublished::class, TriggerSyncPipelines::class);
});
