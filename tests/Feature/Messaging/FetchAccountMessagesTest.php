<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Jobs\FetchAccountMessages;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Conversation;
use App\Models\DirectMessage;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('messages.enabled', true));

test('job persists fetched dms for a consented account', function () {
    Http::fake([
        '*/xrpc/chat.bsky.convo.listConvos*' => Http::response(['convos' => [[
            'id' => 'c1', 'members' => [['did' => 'did:me'], ['did' => 'did:alice', 'handle' => 'alice.test']],
        ]]]),
        '*/xrpc/chat.bsky.convo.getMessages*' => Http::response(['messages' => [
            ['id' => 'm1', 'text' => 'hi', 'sender' => ['did' => 'did:alice'], 'sentAt' => '2026-07-20T10:00:00Z'],
        ]]),
    ]);

    $account = ConnectedAccount::factory()->bluesky()->create([
        'platform' => Platform::Bluesky,
        'remote_account_id' => 'did:me',
        'capabilities' => ['dm_enabled' => true],
    ]);

    // No refresh_token/app_password means TokenManager::fresh() can't refresh or
    // re-login over the network — it falls back to the stored session as-is, which
    // already carries a usable accessJwt for the connector.
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'app_password' => null,
        'session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social'],
    ]);

    FetchAccountMessages::dispatchSync($account);

    expect(Conversation::withoutGlobalScopes()->count())->toBe(1);
    expect(DirectMessage::withoutGlobalScopes()->count())->toBe(1);
});

test('job skips accounts without dm consent', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'capabilities' => []]);

    FetchAccountMessages::dispatchSync($account);

    expect(Conversation::withoutGlobalScopes()->count())->toBe(0);
});
