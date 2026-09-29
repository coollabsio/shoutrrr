<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Jobs\IngestNativePosts;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;

test('is unique per account so a slow poll cannot overlap the next tick and double-post', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);
    $job = new IngestNativePosts($account);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($account->id)
        ->and($job->uniqueFor)->toBe(900);
});
