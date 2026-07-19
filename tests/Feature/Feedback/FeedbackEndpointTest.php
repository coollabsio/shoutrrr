<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

function actingWorkspaceUser(): User
{
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@test.co']);
    $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'name' => 'Acme']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    return $user;
}

it('returns 404 when the feature is disabled', function () {
    config(['feedback.enabled' => false, 'feedback.webhook_url' => null]);
    Http::fake();

    $this->actingAs(actingWorkspaceUser())
        ->postJson(route('feedback.store'), ['type' => 'bug', 'message' => 'hi'])
        ->assertNotFound();

    Http::assertNothingSent();
});

it('returns 404 when enabled but webhook url is missing', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => null]);

    $this->actingAs(actingWorkspaceUser())
        ->postJson(route('feedback.store'), ['type' => 'bug', 'message' => 'hi'])
        ->assertNotFound();
});

it('requires authentication', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);

    $this->postJson(route('feedback.store'), ['type' => 'bug', 'message' => 'hi'])
        ->assertUnauthorized();
});

it('sends a report to discord with server-derived context', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $this->actingAs(actingWorkspaceUser())
        ->postJson(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://app.test/dashboard',
            'browser' => 'Mozilla/5.0',
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];

        return $embed['description'] === 'It broke'
            && collect($embed['fields'])->contains(fn ($f) => $f['value'] === 'ada@test.co')
            && collect($embed['fields'])->contains(fn ($f) => str_contains($f['value'], 'Acme'));
    });
});

it('keeps the full page url on cloud instances', function () {
    config([
        'feedback.enabled' => true,
        'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok',
        'instance.self_hosted' => false,
    ]);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $this->actingAs(actingWorkspaceUser())
        ->postJson(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://acme.example.com/dashboard/posts?tab=drafts',
            'browser' => 'Mozilla/5.0',
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        $page = collect($request['embeds'][0]['fields'])->firstWhere('name', 'Page');

        return $page['value'] === 'https://acme.example.com/dashboard/posts?tab=drafts';
    });
});

it('hides the host in the page url on self-hosted instances', function () {
    config([
        'feedback.enabled' => true,
        'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok',
        'instance.self_hosted' => true,
    ]);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $this->actingAs(actingWorkspaceUser())
        ->postJson(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://acme.example.com/dashboard/posts?tab=drafts',
            'browser' => 'Mozilla/5.0',
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        $page = collect($request['embeds'][0]['fields'])->firstWhere('name', 'Page');

        return $page['value'] === '/dashboard/posts?tab=drafts'
            && ! str_contains($page['value'], 'acme.example.com');
    });
});

it('includes the app environment in the embed', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $this->actingAs(actingWorkspaceUser())
        ->postJson(route('feedback.store'), [
            'type' => 'bug', 'message' => 'hi', 'url' => 'https://app.test', 'browser' => 'UA',
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        $env = collect($request['embeds'][0]['fields'])->firstWhere('name', 'Environment');

        return $env !== null && $env['value'] === app()->environment();
    });
});

it('forwards an attached diagnostics file to discord', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $diagnostics = UploadedFile::fake()->createWithContent(
        'diagnostics.json',
        '{"logs":[{"level":"error","message":"boom"}]}',
    );

    $this->actingAs(actingWorkspaceUser())
        ->post(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://app.test/dashboard',
            'browser' => 'Mozilla/5.0',
            'diagnostics' => $diagnostics,
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->body(), 'diagnostics.json')
        && str_contains($request->body(), 'boom'));
});

it('redacts the operator origin from diagnostics on self-hosted instances', function () {
    config([
        'feedback.enabled' => true,
        'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok',
        'instance.self_hosted' => true,
    ]);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    // Includes an http:// occurrence to prove redaction is scheme-agnostic.
    $diagnostics = UploadedFile::fake()->createWithContent(
        'diagnostics.json',
        '{"network":[{"url":"https://acme.example.com/api/posts"},{"url":"http://acme.example.com/asset.js"},{"url":"https://api.twitter.com/2/tweets"}]}',
    );

    $this->actingAs(actingWorkspaceUser())
        ->post(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://acme.example.com/dashboard',
            'browser' => 'Mozilla/5.0',
            'diagnostics' => $diagnostics,
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->body();

        // Operator host stripped everywhere (path kept), third-party untouched.
        return ! str_contains($body, 'acme.example.com')
            && str_contains($body, '/api/posts')
            && str_contains($body, '/asset.js')
            && str_contains($body, 'api.twitter.com');
    });
});

it('redacts both the submitted url host and the actual request host on self-hosted', function () {
    // Pin the request host so the test doesn't depend on the ambient APP_URL.
    config([
        'feedback.enabled' => true,
        'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok',
        'instance.self_hosted' => true,
        'app.url' => 'https://self-hosted.example',
    ]);
    URL::forceRootUrl('https://self-hosted.example');
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    // The submitted url claims a different host than the actual request host.
    // Same-origin diagnostics carry the request host, so both must be scrubbed.
    $diagnostics = UploadedFile::fake()->createWithContent(
        'diagnostics.json',
        '{"network":[{"url":"https://acme.example.com/api"},{"url":"https://self-hosted.example/internal"}]}',
    );

    $this->actingAs(actingWorkspaceUser())
        ->post(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://acme.example.com/dashboard',
            'browser' => 'Mozilla/5.0',
            'diagnostics' => $diagnostics,
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->body();

        return ! str_contains($body, 'acme.example.com') // client-claimed host
            && ! str_contains($body, 'self-hosted.example') // actual request host
            && str_contains($body, '/internal')
            && str_contains($body, '/api');
    });
});

it('keeps diagnostics urls intact on cloud instances', function () {
    config([
        'feedback.enabled' => true,
        'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok',
        'instance.self_hosted' => false,
    ]);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $diagnostics = UploadedFile::fake()->createWithContent(
        'diagnostics.json',
        '{"network":[{"url":"https://app.shoutrrr.com/api/posts"}]}',
    );

    $this->actingAs(actingWorkspaceUser())
        ->post(route('feedback.store'), [
            'type' => 'bug',
            'message' => 'It broke',
            'url' => 'https://app.shoutrrr.com/dashboard',
            'browser' => 'Mozilla/5.0',
            'diagnostics' => $diagnostics,
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->body(), 'app.shoutrrr.com'));
});

it('attaches an uploaded screenshot as multipart', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $this->actingAs(actingWorkspaceUser())
        ->post(route('feedback.store'), [
            'type' => 'feedback',
            'message' => 'Looks great',
            'url' => 'https://app.test/dashboard',
            'browser' => 'Mozilla/5.0',
            'screenshot' => UploadedFile::fake()->image('shot.png', 800, 600),
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->body(), 'name="files[0]"'));
});

it('validates the request', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);

    $user = actingWorkspaceUser();

    // missing message
    $this->actingAs($user)->postJson(route('feedback.store'), ['type' => 'bug'])
        ->assertJsonValidationErrors('message');

    // bad type
    $this->actingAs($user)->postJson(route('feedback.store'), ['type' => 'rant', 'message' => 'x'])
        ->assertJsonValidationErrors('type');

    // over-length message
    $this->actingAs($user)->postJson(route('feedback.store'), ['type' => 'bug', 'message' => str_repeat('a', 2001)])
        ->assertJsonValidationErrors('message');
});

it('throttles after five requests', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $user = actingWorkspaceUser();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->postJson(route('feedback.store'), [
            'type' => 'bug', 'message' => "n{$i}", 'url' => 'https://app.test', 'browser' => 'UA',
        ])->assertOk();
    }

    $this->actingAs($user)->postJson(route('feedback.store'), [
        'type' => 'bug', 'message' => 'n6', 'url' => 'https://app.test', 'browser' => 'UA',
    ])->assertStatus(429);
});

it('sends with unknown workspace when the user has no current workspace', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    $user = User::factory()->create();
    $user->forceFill(['current_workspace_id' => null])->save();

    $this->actingAs($user)
        ->postJson(route('feedback.store'), [
            'type' => 'bug', 'message' => 'broke', 'url' => 'https://app.test', 'browser' => 'UA',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => collect($request['embeds'][0]['fields'])
        ->contains(fn ($f) => str_contains($f['value'], 'unknown')));
});
