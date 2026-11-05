<?php

use App\Enums\Platform;
use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\SyncPipeline;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function configureXOAuth(): void
{
    config()->set('services.x.client_id', 'cid');
    config()->set('services.x.client_secret', 'secret');
    config()->set('services.x.redirect', 'https://app.test/accounts/callback/x');
}

test('a connect started from the agentic home returns there', function () {
    configureXOAuth();
    ownerActingIn();
    fakeOAuthUser('x', ['id' => 'x-1']);

    $this->get('/accounts/connect/x?return=agentic')->assertRedirect('https://provider.test/oauth');

    $this->get('/accounts/callback/x?error=access_denied')
        ->assertRedirect(route('agentic.home'))
        ->assertSessionHas('error');
});

test('a connect started elsewhere returns to the accounts page', function () {
    configureXOAuth();
    ownerActingIn();
    fakeOAuthUser('x', ['id' => 'x-1']);

    $this->get('/accounts/connect/x')->assertRedirect('https://provider.test/oauth');

    $this->get('/accounts/callback/x?error=access_denied')->assertRedirect(route('accounts.index'));
});

test('an abandoned agentic connect does not change where the next connect lands', function () {
    configureXOAuth();
    ownerActingIn();
    fakeOAuthUser('x', ['id' => 'x-1']);

    $this->get('/accounts/connect/x?return=agentic');
    $this->get('/accounts/connect/x');

    $this->get('/accounts/callback/x?error=access_denied')->assertRedirect(route('accounts.index'));
});

test('agentic home lists connect links that return to the home', function () {
    configureXOAuth();
    ownerActingIn();

    $this->get(route('agentic.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('platforms', fn ($platforms): bool => collect($platforms)->contains(
                fn (array $platform): bool => $platform['platform'] === 'x'
                    && $platform['url'] === route('accounts.connect', ['platform' => 'x']).'?return=agentic'
            ))
        );
});

test('agentic home suggests a sync from a trackable source to the other accounts', function () {
    [, $workspace] = ownerActingIn();
    $linkedin = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'created_at' => now()->subDays(2)]);
    $bluesky = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky, 'created_at' => now()->subDay()]);
    $threads = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Threads]);

    $this->get(route('agentic.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 3)
            ->where('sync.total', 0)
            ->where('sync.can_create', true)
            ->where('sync.suggestion.source_connected_account_id', $bluesky->id)
            ->where('sync.suggestion.destination_connected_account_ids', [$linkedin->id, $threads->id])
        );
});

test('agentic home makes no sync suggestion with one account or an existing pipeline', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->get(route('agentic.home'))
        ->assertInertia(fn (Assert $page) => $page->where('sync.suggestion', null));

    $destination = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $source->id,
    ])->destinations()->sync([$destination->id]);

    $this->get(route('agentic.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sync.total', 1)
            ->where('sync.suggestion', null)
        );
});

test('a new sync pipeline tracks its source by default', function () {
    [, $workspace] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);
    $destination = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->post('/sync', [
        'name' => 'Bluesky sync',
        'source_connected_account_id' => $source->id,
        'destination_connected_account_ids' => [$destination->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('connected_account_native_watches', ['connected_account_id' => $source->id]);
});

test('agentic home shows the empty state when the current workspace is not the user\'s', function () {
    [, $foreign] = ownerActingIn();
    $source = ConnectedAccount::factory()->create(['workspace_id' => $foreign->id]);
    $destination = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $foreign->id]);
    SyncPipeline::factory()->create([
        'workspace_id' => $foreign->id,
        'source_connected_account_id' => $source->id,
    ])->destinations()->sync([$destination->id]);
    Post::factory()->create([
        'workspace_id' => $foreign->id,
        'origin' => PostOrigin::Sync,
        'status' => PostStatus::Published,
    ]);

    $outsider = User::factory()->create(['current_workspace_id' => $foreign->id]);

    $this->actingAs($outsider)
        ->get(route('agentic.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agentic/home')
            ->where('accounts', [])
            ->where('recent_posts', null)
            ->where('sync.total', 0)
            ->where('sync.suggestion', null)
        );
});
