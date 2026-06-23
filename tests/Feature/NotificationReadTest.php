<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

function makeNotification(User $user, ?string $workspaceId, ?string $readAt = null): string
{
    $id = (string) Str::uuid();
    $user->notifications()->create([
        'id' => $id,
        'type' => 'App\\Notifications\\PostPublishedNotification',
        'data' => ['event' => 'post_published', 'title' => 'X', 'body' => '', 'href' => null, 'icon' => 'bell', 'workspace_id' => $workspaceId],
        'read_at' => $readAt,
    ]);

    return $id;
}

test('a user can mark one notification read', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $id = makeNotification($user, $ws->id);

    $this->actingAs($user)->post(route('notifications.read', $id))->assertRedirect();

    expect($user->notifications()->find($id)->read_at)->not->toBeNull();
});

test('a user cannot mark another users notification read', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ws = Workspace::factory()->create();
    $id = makeNotification($owner, $ws->id);

    $this->actingAs($other)->post(route('notifications.read', $id))->assertNotFound();

    expect($owner->notifications()->find($id)->read_at)->toBeNull();
});

test('mark-all-read clears unread for the current workspace and global notifications only', function () {
    $user = User::factory()->create();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $wsA->id])->save();
    makeNotification($user, $wsA->id);
    makeNotification($user, null);
    $bId = makeNotification($user, $wsB->id);

    $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(1);
    expect($user->notifications()->find($bId)->read_at)->toBeNull();
});
