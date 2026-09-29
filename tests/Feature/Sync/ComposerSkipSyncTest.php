<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\Post;

test('creating a draft persists skip_sync', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->postJson('/posts', [
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'segments' => ['hello'],
        'skip_sync' => true,
    ])->assertCreated();

    expect(Post::first()->skip_sync)->toBeTrue();
});
