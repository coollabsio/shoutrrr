<?php

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\DeletePostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;

test('it transitions a published target to deleted', function () {
    Http::fake();

    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tok']);

    $target = PostTarget::factory()->for($post)->published()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'remote_ids' => ['111'],
    ]);

    (new DeletePostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted);
});
