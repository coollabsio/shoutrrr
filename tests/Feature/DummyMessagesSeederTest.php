<?php

declare(strict_types=1);

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SendStatus;
use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\DummyMessagesSeeder;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create(['slug' => 'test-workspace']);
    $this->user = User::factory()->create([
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->forceFill(['owner_id' => $this->user->id])->save();

    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);

    Context::add('workspace_id', $this->workspace->id);
});

test('dummy messages seeder creates a varied DM inbox', function (): void {
    $this->seed(DummyMessagesSeeder::class);

    $conversations = Conversation::query()->where('workspace_id', $this->workspace->id)->get();
    $messages = DirectMessage::query()->where('workspace_id', $this->workspace->id)->get();

    expect($conversations->count())->toBe(DummyMessagesSeeder::CONVERSATION_COUNT)
        ->and($messages->count())->toBeGreaterThan(DummyMessagesSeeder::CONVERSATION_COUNT)
        // Every DM-capable platform is represented.
        ->and($conversations->pluck('platform')->unique()->sort()->values()->all())
        ->toEqual(collect([Platform::X, Platform::Bluesky, Platform::Instagram, Platform::Facebook])->sort()->values()->all())
        // A mix of inbox states for the list + thread.
        ->and($conversations->where('unread_count', '>', 0)->isNotEmpty())->toBeTrue()
        ->and($conversations->whereNotNull('archived_at')->isNotEmpty())->toBeTrue()
        // Meta reply-window states: at least one open and one closed.
        ->and($conversations->first(fn (Conversation $c): bool => ! $c->canReplyNow()))->not->toBeNull()
        ->and($conversations->first(fn (Conversation $c): bool => $c->messaging_window_expires_at?->isFuture() ?? false))->not->toBeNull()
        // Both directions, an outbound with media, and non-sent statuses exist.
        ->and($messages->where('direction', MessageDirection::Inbound)->isNotEmpty())->toBeTrue()
        ->and($messages->where('direction', MessageDirection::Outbound)->isNotEmpty())->toBeTrue()
        ->and($messages->first(fn (DirectMessage $m): bool => ! empty($m->attachments)))->not->toBeNull()
        ->and($messages->whereIn('send_status', [SendStatus::Sending, SendStatus::Failed])->isNotEmpty())->toBeTrue();
});

test('dummy messages seeder is idempotent', function (): void {
    $this->seed(DummyMessagesSeeder::class);
    $firstConversations = Conversation::query()->where('workspace_id', $this->workspace->id)->count();
    $firstMessages = DirectMessage::query()->where('workspace_id', $this->workspace->id)->count();

    $this->seed(DummyMessagesSeeder::class);

    expect(Conversation::query()->where('workspace_id', $this->workspace->id)->count())->toBe($firstConversations)
        ->and(DirectMessage::query()->where('workspace_id', $this->workspace->id)->count())->toBe($firstMessages);
});
