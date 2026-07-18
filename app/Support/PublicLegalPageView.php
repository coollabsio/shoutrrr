<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LegalPageType;
use App\Models\LegalPage;
use App\Services\Legal\LegalPageRenderer;

/**
 * Shapes the minimal, non-identifying payload handed to the public legal page.
 *
 * Only the document type, its neutral title, the rendered HTML, and an
 * effective date are exposed — never the workspace, its slug owner, related
 * projects, users, or any database identifier. This is the guarantee that the
 * public pages leak no information about the instance's tenants.
 */
final class PublicLegalPageView
{
    /**
     * @return array{type: string, title: string, content_html: string, updated_at: string}
     */
    public static function make(LegalPage $page, LegalPageType $type, LegalPageRenderer $renderer): array
    {
        // The publish timestamp is the document's effective date; fall back to
        // the row timestamp only if it is somehow absent.
        $effectiveAt = $page->publishedAtFor($type) ?? $page->updated_at;

        return [
            'type' => $type->value,
            'title' => $type->title(),
            'content_html' => $renderer->toHtml($page->bodyFor($type)),
            'updated_at' => $effectiveAt->toIso8601String(),
        ];
    }
}
