<?php

use App\Enums\Platform;
use App\Services\Repost\RepostConnectorRegistry;

test('registry rejects platforms without native repost', function (): void {
    app(RepostConnectorRegistry::class)->for(Platform::Instagram);
})->throws(InvalidArgumentException::class);
