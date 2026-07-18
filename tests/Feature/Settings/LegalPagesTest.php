<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\LegalPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;

/**
 * @return array{0: Workspace, 1: User}
 */
function legalWorkspaceOwner(): array
{
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    return [$workspace, $owner];
}

test('owner can view the legal settings page with the current values', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'acme-legal',
        'terms_body' => '# Terms',
    ]);

    $this->actingAs($owner)
        ->get(route('settings.workspace.legal'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/legal')
            ->where('legal.slug', 'acme-legal')
            ->where('legal.terms.body', '# Terms')
            ->where('legal.terms.published', true)
            ->where('legal.privacy.published', true));
});

test('an admin can view and update the legal pages', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $admin->id,
        'role' => WorkspaceRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get(route('settings.workspace.legal'))
        ->assertOk();

    $this->actingAs($admin)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'admin-legal',
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('legal_pages', [
        'workspace_id' => $workspace->id,
        'slug' => 'admin-legal',
    ]);
});

test('a plain member cannot view the legal settings page', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)
        ->get(route('settings.workspace.legal'))
        ->assertForbidden();
});

test('a plain member cannot update the legal pages', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'member-legal',
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('legal_pages', 0);
});

test('owner can create the legal page with a slug and both documents published', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->from(route('settings.workspace.legal'))
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => '<h2>Terms</h2><p>By using this you agree.</p>',
            'privacy_body' => '<h2>Privacy</h2><p>We protect your data.</p>',
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('legal_pages', [
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
    ]);

    $page = LegalPage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

    expect($page)->not->toBeNull()
        ->and($page->slug)->toBe('company-legal')
        ->and($page->terms_published_at)->not->toBeNull()
        ->and($page->privacy_published_at)->not->toBeNull();
});

test('publishing a document with no visible content fails validation', function (string $body): void {
    [, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => $body,
            'terms_published' => true,
            'privacy_body' => '<p>Privacy content</p>',
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_body');
})->with([
    'empty string' => [''],
    'empty editor markup' => ['<p></p>'],
    'whitespace only' => ['<p>   </p>'],
]);

test('dangerous HTML is stripped before it is stored', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => '<h2>Terms</h2><p onclick="steal()">ok</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
            'privacy_body' => '<p>Privacy</p>',
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $page = LegalPage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

    expect($page->terms_body)
        ->toContain('<h2>Terms</h2>')
        ->toContain('ok')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

test('a body longer than the configured maximum is rejected', function (): void {
    [, $owner] = legalWorkspaceOwner();

    $max = (int) config('kit.legal.max_body_length');

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => str_repeat('a', $max + 1),
            'privacy_body' => 'Privacy content',
            'terms_published' => false,
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_body');
});

test('unpublishing one document leaves the other untouched', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $original = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);

    $page = LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
        'terms_published_at' => $original,
        'privacy_published_at' => $original,
    ]);

    $this->travelTo(CarbonImmutable::create(2026, 6, 15, 8, 30, 0));

    $this->actingAs($owner)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => $page->terms_body,      // unchanged
            'privacy_body' => $page->privacy_body,  // unchanged
            'terms_published' => false,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->travelBack();
    $page->refresh();

    expect($page->terms_published_at)->toBeNull()
        ->and($page->privacy_published_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00');
});

test('a slug already owned by another workspace is rejected', function (): void {
    [, $owner] = legalWorkspaceOwner();

    $otherWorkspace = Workspace::factory()->create();
    LegalPage::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'slug' => 'taken-legal',
    ]);

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => 'taken-legal',
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => false,
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('reserved and malformed slugs are rejected', function (string $slug): void {
    [, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => $slug,
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => false,
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with([
    'reserved word' => ['settings'],
    'reserved terms' => ['terms'],
    'uppercase and punctuation' => ['Bad Slug!'],
    'too short' => ['ab'],
]);

test('re-saving a published document with unchanged content preserves its date', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $original = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);

    $page = LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
        'terms_published_at' => $original,
        'privacy_published_at' => $original,
    ]);

    $this->travelTo(CarbonImmutable::create(2026, 6, 15, 8, 30, 0));

    $this->actingAs($owner)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => $page->terms_body,      // unchanged
            'privacy_body' => $page->privacy_body,  // unchanged
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->travelBack();
    $page->refresh();

    expect($page->terms_published_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00')
        ->and($page->privacy_published_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00');
});

test('editing a published document advances its last-updated date', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $original = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);

    $page = LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
        'terms_published_at' => $original,
        'privacy_published_at' => $original,
    ]);

    $this->travelTo(CarbonImmutable::create(2026, 6, 15, 8, 30, 0));

    $this->actingAs($owner)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => '<p>Materially rewritten terms.</p>',  // changed
            'privacy_body' => $page->privacy_body,                 // unchanged
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->travelBack();
    $page->refresh();

    // The edited document's date advances; the untouched one is preserved.
    expect($page->terms_published_at->format('Y-m-d H:i:s'))->toBe('2026-06-15 08:30:00')
        ->and($page->privacy_published_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00');
});
