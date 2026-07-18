<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

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
