<?php

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Notifications\AccountNeedsAttentionNotification;
use App\Notifications\PostPublishedNotification;
use App\Notifications\PublishFailedNotification;
use Illuminate\Support\Facades\Notification;

test('successful publish notifies the author', function () {
    Notification::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Publishing]);

    // Drive onSuccess directly (the connector layer is exercised elsewhere).
    (new PublishPostTarget($target))->notifyPublished($target);

    Notification::assertSentTo($user, PostPublishedNotification::class);
});

test('terminal non-auth failure notifies the author of publish failure', function () {
    Notification::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create();

    (new PublishPostTarget($target))->notifyFailed($target, ErrorKind::Unknown);

    Notification::assertSentTo($user, PublishFailedNotification::class);
    Notification::assertNotSentTo($user, AccountNeedsAttentionNotification::class);
});

test('uncaught publish job failure notifies the author of publish failure', function () {
    Notification::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Publishing]);

    (new PublishPostTarget($target))->failed(new RuntimeException('Unexpected publisher crash'));

    Notification::assertSentTo($user, PublishFailedNotification::class);
});

test('terminal auth-expired failure notifies account-needs-attention instead', function () {
    Notification::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    $target = PostTarget::factory()->for($post)->create();

    (new PublishPostTarget($target))->notifyFailed($target, ErrorKind::AuthExpired);

    Notification::assertSentTo($user, AccountNeedsAttentionNotification::class);
    Notification::assertNotSentTo($user, PublishFailedNotification::class);
});

test('published notification payload identifies the post, platform and account', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create(['base_text' => 'Launching our brand new analytics dashboard today']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky->value, 'handle' => '@acme.bsky.social']);
    $target = PostTarget::factory()->for($post)->for($account, 'account')->create(['platform' => Platform::Bluesky->value]);

    $payload = (new PostPublishedNotification($target))->toArray($user);

    expect($payload['title'])->toBe('Published to Bluesky')
        ->and($payload['body'])->toContain('@acme.bsky.social')
        ->and($payload['body'])->toContain('Launching our brand new analytics dashboard')
        ->and($payload['href'])->toBe(route('posts.show', $post));
});

test('failed notification payload identifies the post, platform and account', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create(['base_text' => 'Big announcement coming soon']);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value, 'handle' => '@acme']);
    $target = PostTarget::factory()->for($post)->for($account, 'account')->create(['platform' => Platform::X->value]);

    $payload = (new PublishFailedNotification($target))->toArray($user);

    expect($payload['title'])->toBe('Failed to publish to X')
        ->and($payload['body'])->toContain('@acme')
        ->and($payload['body'])->toContain('Big announcement coming soon')
        ->and($payload['href'])->toBe(route('posts.show', $post));
});

test('account needs attention notification links to accounts page', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value, 'handle' => '@acme']);

    $payload = (new AccountNeedsAttentionNotification($account, 'ws-123'))->toArray($user);

    expect($payload['title'])->toBe('X account needs attention')
        ->and($payload['body'])->toBe('@acme needs to be reconnected.')
        ->and($payload['href'])->toBe(route('accounts.index'));
});

test('payload excerpt falls back for media-only posts', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create(['base_text' => '']);
    $target = PostTarget::factory()->for($post)->create();

    $payload = (new PostPublishedNotification($target))->toArray($user);

    expect($payload['body'])->toContain('Media post');
});
