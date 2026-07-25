<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
});

test('messages route 404s when disabled', function (): void {
    config()->set('messages.enabled', false);

    $this->actingAs($this->user)->get('/messages')->assertNotFound();
});

test('messages route renders when enabled', function (): void {
    config()->set('messages.enabled', true);

    $this->actingAs($this->user)->get('/messages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('messages/index'));
});
