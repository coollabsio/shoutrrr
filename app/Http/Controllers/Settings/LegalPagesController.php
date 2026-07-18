<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLegalPageRequest;
use App\Models\LegalPage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function update(UpdateLegalPageRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        // Load the current row first so we can preserve each document's original
        // publish timestamp when it stays published across the save.
        $existing = LegalPage::query()->where('workspace_id', $workspace->id)->first();
        $validated = $request->validated();

        LegalPage::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'slug' => $validated['slug'],
                'terms_body' => $validated['terms_body'] ?? null,
                'privacy_body' => $validated['privacy_body'] ?? null,
                'terms_published_at' => $this->resolvePublishTimestamp(
                    $request->boolean('terms_published'),
                    $existing?->terms_published_at,
                ),
                'privacy_published_at' => $this->resolvePublishTimestamp(
                    $request->boolean('privacy_published'),
                    $existing?->privacy_published_at,
                ),
            ],
        );

        return back()->with('success', 'Legal pages saved.');
    }

    /**
     * Decide the stored publish timestamp for a document. Publishing keeps the
     * existing "published at" date when there is one (so re-saving does not move
     * the effective date) and stamps now on first publish; unpublishing clears it.
     */
    private function resolvePublishTimestamp(bool $published, ?CarbonInterface $current): ?CarbonInterface
    {
        if (! $published) {
            return null;
        }

        return $current ?? now();
    }
}
