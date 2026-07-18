<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two legal documents a workspace can publish under its public slug.
 *
 * Each case owns the mapping to its storage columns on the single
 * `legal_pages` row and the neutral, public-facing title rendered on the page.
 * Keeping that mapping here means the controllers, service, and views never
 * hard-code column names or titles.
 */
enum LegalPageType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';

    /**
     * Neutral, public-facing heading. Deliberately generic so the page never
     * echoes the workspace, project, or owner identity.
     */
    public function title(): string
    {
        return match ($this) {
            self::Terms => 'Terms of Service',
            self::Privacy => 'Privacy Policy',
        };
    }

    /**
     * Column on `legal_pages` holding this document's Markdown source.
     */
    public function bodyColumn(): string
    {
        return match ($this) {
            self::Terms => 'terms_body',
            self::Privacy => 'privacy_body',
        };
    }

    /**
     * Column on `legal_pages` holding this document's publish timestamp
     * (null while the document is an unpublished draft).
     */
    public function publishedAtColumn(): string
    {
        return match ($this) {
            self::Terms => 'terms_published_at',
            self::Privacy => 'privacy_published_at',
        };
    }
}
