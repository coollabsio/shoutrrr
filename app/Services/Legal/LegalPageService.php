<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Enums\LegalPageType;
use App\Models\LegalPage;

class LegalPageService
{
    /**
     * Resolve the workspace legal page that should be served for a public
     * `/{slug}/{type}` request, or null when nothing publishable matches.
     *
     * The slug is the only public identifier. A guest carries no workspace
     * context and an authenticated visitor must still be able to view legal
     * pages that belong to other workspaces, so the workspace global scope is
     * removed for this cross-workspace read (mirroring the public share flow).
     */
    public function resolvePublished(string $slug, LegalPageType $type): ?LegalPage
    {
        $page = LegalPage::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->first();

        return $page?->isPublished($type) === true ? $page : null;
    }
}
