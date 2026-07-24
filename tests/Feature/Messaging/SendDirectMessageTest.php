<?php

declare(strict_types=1);

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SendStatus;
use App\Jobs\SendDirectMessage;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Conversation;
use App\Models\DirectMessage;
use Illuminate\Support\Facades\Http;

test('send job stamps sent status and remote id on success', function () {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-9']], 201)]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create(['platform' => Platform::X, 'remote_conversation_id' => 'c-9']);
    $outgoing = DirectMessage::factory()->for($convo)->create([
        'direction' => MessageDirection::Outbound, 'is_ours' => true, 'send_status' => SendStatus::Sending,
        'remote_message_id' => 'pending:'.\Illuminate\Support\Str::uuid(), 'text' => 'hey',
    ]);

    SendDirectMessage::dispatchSync($outgoing->id, $convo->id, 'hey', Platform::X);

    $outgoing->refresh();
    expect($outgoing->send_status)->toBe(SendStatus::Sent);
    expect($outgoing->our_remote_id)->toBe('sent-9');
});

test('send job marks failed on connector failure', function () {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response('boom', 500)]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create(['platform' => Platform::X]);
    $outgoing = DirectMessage::factory()->for($convo)->create(['direction' => MessageDirection::Outbound, 'is_ours' => true, 'send_status' => SendStatus::Sending]);

    SendDirectMessage::dispatchSync($outgoing->id, $convo->id, 'hey', Platform::X);

    expect($outgoing->refresh()->send_status)->toBe(SendStatus::Failed);
});
