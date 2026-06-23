<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PostPublishedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

test('shared notifications prop only includes current-workspace notifications', function () {
    $user = User::factory()->create();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $wsA->id])->save();

    // two stored database notifications, one per workspace
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PostPublishedNotification::class,
        'data' => ['event' => 'post_published', 'title' => 'A', 'body' => '', 'href' => null, 'icon' => 'check-circle', 'workspace_id' => $wsA->id],
        'read_at' => null,
    ]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PostPublishedNotification::class,
        'data' => ['event' => 'post_published', 'title' => 'B', 'body' => '', 'href' => null, 'icon' => 'check-circle', 'workspace_id' => $wsB->id],
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 1)
            ->where('notifications.items.0.title', 'A')
        );
});

test('shared notifications prop includes global notifications in the current workspace feed', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PostPublishedNotification::class,
        'data' => ['event' => 'workspace_invite', 'title' => 'Workspace invitation', 'body' => '', 'href' => null, 'icon' => 'users', 'workspace_id' => null],
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 1)
            ->where('notifications.items.0.title', 'Workspace invitation')
        );
});

test('notifications are ordered by id desc when created_at is identical', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();

    $sameTime = Carbon::now();

    // Lower id (sorts first without tiebreaker) — title 'Earlier'
    $user->notifications()->create([
        'id' => '10000000-0000-0000-0000-000000000001',
        'type' => PostPublishedNotification::class,
        'data' => ['event' => 'post_published', 'title' => 'Earlier', 'body' => '', 'href' => null, 'icon' => 'bell', 'workspace_id' => $ws->id],
        'read_at' => null,
        'created_at' => $sameTime,
        'updated_at' => $sameTime,
    ]);

    // Higher id (should sort first with tiebreaker) — title 'Later'
    $user->notifications()->create([
        'id' => '20000000-0000-0000-0000-000000000002',
        'type' => PostPublishedNotification::class,
        'data' => ['event' => 'post_published', 'title' => 'Later', 'body' => '', 'href' => null, 'icon' => 'bell', 'workspace_id' => $ws->id],
        'read_at' => null,
        'created_at' => $sameTime,
        'updated_at' => $sameTime,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('notifications.items.0.title', 'Later')
            ->where('notifications.items.1.title', 'Earlier')
        );
});
