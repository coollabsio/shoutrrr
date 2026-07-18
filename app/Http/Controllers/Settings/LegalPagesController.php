<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLegalPageRequest;
use App\Models\LegalPage;
use App\Models\User;
use App\Services\Legal\LegalHtmlSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-owner/admin surface for configuring the public Terms & Privacy
 * pages: the shared public slug plus the Markdown source and publish state of
 * each document. Gated on the `workspace.settings.manage` permission, matching
 * every other workspace setting.
 */
class LegalPagesController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspace->id), 403);

        $page = LegalPage::query()->where('workspace_id', $workspace->id)->first();

        return Inertia::render('settings/workspace/legal', [
            'legal' => [
                'slug' => $page?->slug,
                'terms' => [
                    'body' => $page?->terms_body,
                    'published' => $page?->terms_published_at !== null,
                ],
                'privacy' => [
                    'body' => $page?->privacy_body,
                    'published' => $page?->privacy_published_at !== null,
                ],
            ],
        ]);
    }

    public function update(UpdateLegalPageRequest $request, LegalHtmlSanitizer $sanitizer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        // Load the current row first so we can compare bodies and preserve each
        // document's published date when its content is unchanged.
        $existing = LegalPage::query()->where('workspace_id', $workspace->id)->first();
        $validated = $request->validated();

        // Sanitize on write — the server is the security boundary, not the
        // editor. sanitize() also collapses an empty document to null.
        $termsBody = $sanitizer->sanitize($validated['terms_body'] ?? null);
        $privacyBody = $sanitizer->sanitize($validated['privacy_body'] ?? null);

        $attributes = [
            'slug' => $validated['slug'],
            'terms_body' => $termsBody,
            'privacy_body' => $privacyBody,
            'terms_published_at' => $this->resolvePublishTimestamp(
                $request->boolean('terms_published'),
                $termsBody !== $existing?->terms_body,
                $existing?->terms_published_at,
            ),
            'privacy_published_at' => $this->resolvePublishTimestamp(
                $request->boolean('privacy_published'),
                $privacyBody !== $existing?->privacy_body,
                $existing?->privacy_published_at,
            ),
        ];

        try {
            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                LegalPage::query()->create($attributes + ['workspace_id' => $workspace->id]);
            }
        } catch (UniqueConstraintViolationException) {
            // Two workspaces can pass the unique-slug check and then race to
            // insert; the loser hits the DB constraint. Surface it as a normal
            // validation error instead of a 500.
            throw ValidationException::withMessages([
                'slug' => 'That slug is already in use. Please choose another.',
            ]);
        }

        return back()->with('success', 'Legal pages saved.');
    }

    /**
     * Decide the stored publish timestamp for a document, which doubles as the
     * "last updated" date shown on the public page. Unpublishing clears it;
     * publishing keeps the existing date only while the content is unchanged, and
     * otherwise stamps now — so the public date advances on first publish and
     * whenever the body is edited.
     */
    private function resolvePublishTimestamp(bool $published, bool $bodyChanged, ?CarbonInterface $current): ?CarbonInterface
    {
        if (! $published) {
            return null;
        }

        if ($current !== null && ! $bodyChanged) {
            return $current;
        }

        return now();
    }
}
