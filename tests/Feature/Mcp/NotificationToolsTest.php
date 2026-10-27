<?php

use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function notificationToolJson(TestResponse $response): array
{
    $payload = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($payload['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

function seedNotification(User $user, ?string $workspaceId, string $title, bool $read = false, int $minutesAgo = 0): string
{
    $id = (string) Str::uuid();
    $notification = $user->notifications()->create([
        'id' => $id,
        'type' => 'App\\Notifications\\PostPublishedNotification',
        'data' => ['event' => 'post_failed', 'title' => $title, 'body' => 'body', 'href' => null, 'icon' => 'bell', 'workspace_id' => $workspaceId],
        'read_at' => $read ? Date::now() : null,
    ]);
    $notification->forceFill(['created_at' => Date::now()->subMinutes($minutesAgo)])->save();

    return $id;
}

test('list_notifications returns the bound workspace and global notifications, newest first', function (): void {
    [$user, $workspace] = mcpOwner();
    $other = Workspace::factory()->create();
    $mine = seedNotification($user, $workspace->id, 'Publish failed', minutesAgo: 1);
    $global = seedNotification($user, null, 'Invited to a workspace', read: true, minutesAgo: 5);
    seedNotification($user, $other->id, 'Other workspace event');
    seedNotification(User::factory()->create(), $workspace->id, 'Someone else');

    $response = ShoutrrrServer::actingAs($user)->tool(ListNotificationsTool::class, []);
    $response->assertOk();
    $data = notificationToolJson($response);

    expect($data['unread_count'])->toBe(1)
        ->and(array_column($data['notifications'], 'id'))->toBe([$mine, $global])
        ->and(array_column($data['notifications'], 'title'))->toBe(['Publish failed', 'Invited to a workspace'])
        ->and(array_column($data['notifications'], 'read'))->toBe([false, true])
        ->and($data['notifications'][0]['event'])->toBe('post_failed');
});

test('list_notifications filters to unread and honors the limit', function (): void {
    [$user, $workspace] = mcpOwner();
    seedNotification($user, $workspace->id, 'Old unread', minutesAgo: 10);
    seedNotification($user, $workspace->id, 'New unread', minutesAgo: 1);
    seedNotification($user, $workspace->id, 'Read one', read: true);

    $unread = notificationToolJson(ShoutrrrServer::actingAs($user)->tool(ListNotificationsTool::class, ['unread_only' => true]));
    $limited = notificationToolJson(ShoutrrrServer::actingAs($user)->tool(ListNotificationsTool::class, ['unread_only' => true, 'limit' => 1]));

    expect(array_column($unread['notifications'], 'title'))->toBe(['New unread', 'Old unread'])
        ->and(array_column($limited['notifications'], 'title'))->toBe(['New unread'])
        ->and($limited['unread_count'])->toBe(2);
});

test('mark_notifications_read marks one notification', function (): void {
    [$user, $workspace] = mcpOwner();
    $target = seedNotification($user, $workspace->id, 'Target');
    $untouched = seedNotification($user, $workspace->id, 'Untouched');

    $response = ShoutrrrServer::actingAs($user)->tool(MarkNotificationsReadTool::class, ['notification_id' => $target]);
    $response->assertOk();

    expect(notificationToolJson($response))->toBe(['marked_read' => 1])
        ->and($user->notifications()->find($target)->read_at)->not->toBeNull()
        ->and($user->notifications()->find($untouched)->read_at)->toBeNull();
});

test('mark_notifications_read marks all unread in the bound workspace and global ones only', function (): void {
    [$user, $workspace] = mcpOwner();
    $mine = seedNotification($user, $workspace->id, 'Mine');
    $global = seedNotification($user, null, 'Global');
    $foreign = seedNotification($user, Workspace::factory()->create()->id, 'Foreign');

    $response = ShoutrrrServer::actingAs($user)->tool(MarkNotificationsReadTool::class, []);

    expect(notificationToolJson($response))->toBe(['marked_read' => 2])
        ->and($user->notifications()->find($mine)->read_at)->not->toBeNull()
        ->and($user->notifications()->find($global)->read_at)->not->toBeNull()
        ->and($user->notifications()->find($foreign)->read_at)->toBeNull();
});

test('mark_notifications_read rejects a notification from another workspace or another user', function (): void {
    [$user] = mcpOwner();
    $foreign = seedNotification($user, Workspace::factory()->create()->id, 'Foreign');
    $stranger = User::factory()->create();
    $strangers = seedNotification($stranger, null, 'Not yours');

    ShoutrrrServer::actingAs($user)->tool(MarkNotificationsReadTool::class, ['notification_id' => $foreign])
        ->assertHasErrors(['No notification with that id exists for you in this workspace.']);
    ShoutrrrServer::actingAs($user)->tool(MarkNotificationsReadTool::class, ['notification_id' => $strangers])
        ->assertHasErrors(['No notification with that id exists for you in this workspace.']);

    expect($user->notifications()->find($foreign)->read_at)->toBeNull()
        ->and($stranger->notifications()->find($strangers)->read_at)->toBeNull();
});

test('notification tools are denied once the user leaves the bound workspace', function (): void {
    [$user, $workspace] = mcpOwner();
    $id = seedNotification($user, $workspace->id, 'Mine');
    WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->delete();

    ShoutrrrServer::actingAs($user->fresh())->tool(ListNotificationsTool::class, [])
        ->assertHasErrors(['not bound to a workspace']);
    ShoutrrrServer::actingAs($user->fresh())->tool(MarkNotificationsReadTool::class, [])
        ->assertHasErrors(['not bound to a workspace']);

    expect($user->notifications()->find($id)->read_at)->toBeNull();
});
