<?php

use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\AccountNeedsAttentionNotification;
use App\Notifications\PostPublishedNotification;
use App\Notifications\PublishFailedNotification;
use App\Notifications\WorkspaceInviteNotification;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

test('post published notifies in-app only by default', function () {
    Notification::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create();

    $user->notify(new PostPublishedNotification($target));

    Notification::assertSentTo($user, PostPublishedNotification::class, function ($notification) use ($user) {
        return $notification->via($user) === ['database'];
    });
});

test('publish failed notifies on both channels by default', function () {
    Notification::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create();

    $user->notify(new PublishFailedNotification($target));

    Notification::assertSentTo($user, PublishFailedNotification::class, function ($notification) use ($user) {
        return $notification->via($user) === ['database', 'mail'];
    });
});

test('post published respects disabled preferences', function () {
    Notification::fake();
    $user = User::factory()->create([
        'notification_preferences' => NotificationPreferences::fromArray([
            'post_published' => ['in_app' => true, 'mail' => false],
        ]),
    ]);
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create();

    $user->notify(new PostPublishedNotification($target));

    Notification::assertSentTo($user, PostPublishedNotification::class, function ($notification) use ($user) {
        return $notification->via($user) === ['database'];
    });
});

test('workspace invite to an existing user resolves to in-app and mail channels by default', function () {
    $user = User::factory()->create();
    $invitation = WorkspaceInvitation::factory()->create();
    $notification = new WorkspaceInviteNotification($invitation, 'plain-token');

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via($user))->toBe(['database', 'mail'])
        ->and($notification->viaConnections())->toBe([
            'database' => 'sync',
            'mail' => 'sync',
        ]);
});

test('workspace invite to an unregistered email resolves to the mail channel', function () {
    $invitation = WorkspaceInvitation::factory()->create();
    $anonymous = (new AnonymousNotifiable)->route('mail', 'newbie@example.com');

    expect((new WorkspaceInviteNotification($invitation, 'plain-token'))->via($anonymous))->toBe(['mail']);
});

test('account-needs-attention in-app channel cannot be silenced', function () {
    Notification::fake();
    $user = User::factory()->create([
        'notification_preferences' => NotificationPreferences::fromArray([
            'account_needs_attention' => ['in_app' => false, 'mail' => false],
        ]),
    ]);
    $account = ConnectedAccount::factory()->create();

    $user->notify(new AccountNeedsAttentionNotification($account, 'ws-123'));

    Notification::assertSentTo($user, AccountNeedsAttentionNotification::class, function ($notification) use ($user) {
        return $notification->via($user) === ['database'];
    });
});
