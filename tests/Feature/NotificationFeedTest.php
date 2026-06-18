<?php

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PostPublishedNotification;
use App\Support\Notifications\NotificationPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function seedNotifications(User $user, string $workspaceId, int $count): void
{
    $base = Carbon::now()->subMinutes($count);

    for ($i = 0; $i < $count; $i++) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => PostPublishedNotification::class,
            'data' => ['event' => 'post_published', 'title' => "N{$i}", 'body' => '', 'href' => null, 'icon' => 'bell', 'workspace_id' => $workspaceId],
            'read_at' => null,
            'created_at' => $base->copy()->addMinutes($i),
            'updated_at' => $base->copy()->addMinutes($i),
        ]);
    }
}

test('the feed returns only the first page and a cursor when more exist', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    seedNotifications($user, $ws->id, NotificationPresenter::PER_PAGE + 5);

    $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonCount(NotificationPresenter::PER_PAGE, 'items')
        ->assertJsonPath('nextCursor', fn ($cursor) => is_string($cursor) && $cursor !== '');
});

test('following the cursor returns the remaining notifications and then stops', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $ws->id])->save();
    $total = NotificationPresenter::PER_PAGE + 5;
    seedNotifications($user, $ws->id, $total);

    $firstPage = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->json();

    $secondPage = $this->actingAs($user)
        ->getJson(route('notifications.index', ['cursor' => $firstPage['nextCursor']]))
        ->assertOk()
        ->assertJsonCount($total - NotificationPresenter::PER_PAGE, 'items')
        ->assertJsonPath('nextCursor', null)
        ->json();

    // No overlap between pages.
    $firstIds = array_column($firstPage['items'], 'id');
    $secondIds = array_column($secondPage['items'], 'id');
    expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
});

test('the feed is scoped to the current workspace', function () {
    $user = User::factory()->create();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $wsA->id])->save();
    seedNotifications($user, $wsA->id, 2);
    seedNotifications($user, $wsB->id, 3);

    $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonCount(2, 'items');
});

test('the feed requires authentication', function () {
    $this->getJson(route('notifications.index'))->assertUnauthorized();
});
