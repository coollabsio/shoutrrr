<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LegalPageType;
use App\Services\Legal\LegalPageRenderer;
use App\Services\Legal\LegalPageService;
use App\Support\PublicLegalPageView;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves a workspace's published Terms / Privacy documents at
 * `/{slug}/terms` and `/{slug}/privacy`.
 *
 * The route is public and unauthenticated. It is deliberately hardened: the
 * `{document}` segment is constrained to the two known types, responses carry
 * `X-Robots-Tag: noindex` (via the NoIndex middleware) and are rate limited,
 * and every non-servable request returns an identical 404 so the endpoint
 * cannot be used to enumerate workspaces or slugs.
 */
class PublicLegalPageController extends Controller
{
    public function show(
        string $slug,
        string $document,
        LegalPageService $service,
        LegalPageRenderer $renderer,
    ): Response {
        // The route already constrains {document} to `terms|privacy`; tryFrom is
        // defence in depth so an unexpected value can never reach the query.
        $type = LegalPageType::tryFrom($document);
        abort_if($type === null, 404);

        $page = $service->resolvePublished($slug, $type);

        // An unknown slug and an unpublished document return the SAME 404: the
        // response must never reveal whether a workspace or slug exists.
        abort_if($page === null, 404);

        return Inertia::render('legal/show', [
            'page' => PublicLegalPageView::make($page, $type, $renderer),
        ]);
    }
}
