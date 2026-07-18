export type LegalPageType = 'terms' | 'privacy';

/**
 * Minimal, non-identifying payload rendered on the public
 * `/{slug}/terms` and `/{slug}/privacy` pages. `content_html` is already
 * sanitized server-side and safe to inject.
 */
export type PublicLegalPageView = {
    type: LegalPageType;
    title: string;
    content_html: string;
    updated_at: string; // ISO-8601
};

/** A single managed document within the workspace legal settings payload. */
export type LegalDocumentSettings = {
    body: string | null;
    published: boolean;
};

/** Owner/admin management payload for `settings/workspace/legal`. */
export type LegalSettings = {
    slug: string | null;
    terms: LegalDocumentSettings;
    privacy: LegalDocumentSettings;
};
