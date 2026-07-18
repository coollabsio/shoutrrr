<?php

declare(strict_types=1);

use App\Models\LegalPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('serves a published terms page with a non-indexable, non-identifying payload', function (): void {
    $page = LegalPage::factory()->create([
        'slug' => 'acme-legal',
        'terms_body' => '<h2>Terms</h2><p>By using this service you agree to the following terms.</p>',
    ]);

    $this->get('/acme-legal/terms')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($assert) => $assert
            ->component('legal/show')
            ->has('page', 4)
            ->has('page', fn ($prop) => $prop
                ->where('type', 'terms')
                ->where('title', 'Terms of Service')
                // The stored HTML is served as-is (already sanitized on write).
                ->where('content_html', '<h2>Terms</h2><p>By using this service you agree to the following terms.</p>')
                // The public "last updated" date is the document's publish timestamp.
                ->where('updated_at', $page->terms_published_at->toIso8601String())
            )
        );
});

test('serves a published privacy page with its neutral title', function (): void {
    LegalPage::factory()->create([
        'slug' => 'acme-legal',
        'privacy_body' => '<h2>Privacy</h2><p>We respect your privacy and only collect what we need.</p>',
    ]);

    $this->get('/acme-legal/privacy')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page
            ->component('legal/show')
            ->has('page', 4)
            ->has('page', fn ($prop) => $prop
                ->where('type', 'privacy')
                ->where('title', 'Privacy Policy')
                ->where('content_html', fn ($html) => str_contains((string) $html, 'We respect your privacy'))
                ->where('updated_at', fn ($value) => is_string($value) && $value !== '')
            )
        );
});

test('exposes only the four whitelisted keys and never leaks tenant metadata', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Secret Tenant Name']);
    LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'acme-legal',
    ]);

    // `has('page', 4)` asserts the prop is an array of EXACTLY four items, and
    // the scoped closure's implicit interacted() check fails if `page` carries
    // any property beyond the four named ones (workspace id/name/slug, user
    // data, row timestamps, ...). Together they pin the payload to the
    // non-identifying shape.
    $this->get('/acme-legal/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('legal/show')
            ->has('page', 4)
            ->has('page', fn ($prop) => $prop
                ->has('type')
                ->has('title')
                ->has('content_html')
                ->has('updated_at')
            )
        );
});

test('returns an identical 404 for an unpublished document and for an unknown slug', function (): void {
    LegalPage::factory()->unpublished()->create(['slug' => 'drafted-legal']);

    $unpublished = $this->get('/drafted-legal/terms');
    $unknown = $this->get('/no-such-slug/terms');

    $unpublished->assertNotFound();
    $unknown->assertNotFound();
    expect($unpublished->getStatusCode())->toBe($unknown->getStatusCode());
});

test('returns 404 when only the other document is published', function (): void {
    // termsOnly() publishes terms but leaves privacy an unpublished draft.
    LegalPage::factory()->termsOnly()->create(['slug' => 'partial-legal']);

    $this->get('/partial-legal/terms')->assertOk();
    $this->get('/partial-legal/privacy')->assertNotFound();
});

test('returns 404 for a document type outside terms or privacy', function (): void {
    LegalPage::factory()->create(['slug' => 'acme-legal']);

    $this->get('/acme-legal/cookies')->assertNotFound();
    $this->get('/acme-legal/somethingelse')->assertNotFound();
});

test('is visible across workspaces because the global scope is bypassed', function (): void {
    $workspaceB = Workspace::factory()->create();
    LegalPage::factory()->create([
        'workspace_id' => $workspaceB->id,
        'slug' => 'workspace-b-legal',
        'terms_body' => '<p>Cross workspace visible content.</p>',
    ]);

    $workspaceA = Workspace::factory()->create();
    $userA = User::factory()->create(['current_workspace_id' => $workspaceA->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspaceA->id,
        'user_id' => $userA->id,
    ]);

    $this->actingAs($userA)
        ->get('/workspace-b-legal/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('legal/show')
            ->where('page.type', 'terms')
            ->where('page.content_html', fn ($html) => str_contains((string) $html, 'Cross workspace visible content'))
        );
});
