<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishContext;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;

test('mediaForSection returns the resolved media for an index, empty otherwise', function (): void {
    $ctx = new PublishContext(
        target: new PostTarget,
        segments: ['a', 'b'],
        media: [],
        account: new ConnectedAccount,
        credentials: [],
        mediaBySection: [1 => [tap(new PostMedia, fn ($m) => $m->id = 'm1')]],
    );

    expect($ctx->mediaForSection(0))->toBe([]);
    expect($ctx->mediaForSection(1)[0]->id)->toBe('m1');
});
