<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for LinkedIn organization URNs used when tagging orgs in a post.
 *
 * A real LinkedIn mention is emitted inline in the post commentary as
 * `@[Visible Name](urn:li:organization:ID)`. The visible name must match the
 * organization's name exactly (case sensitive) or LinkedIn silently renders it
 * as plain text. See app/Services/Posts/DraftService.php for where this is used.
 */
final class LinkedInOrg
{
    /**
     * Coerce a user-supplied reference into a canonical org URN, or null when it
     * cannot be resolved to a numeric organization id without an API lookup.
     *
     * Accepts:
     *  - `urn:li:organization:12345`      → itself
     *  - `12345`                          → `urn:li:organization:12345`
     *  - `https://www.linkedin.com/company/12345/` → `urn:li:organization:12345`
     *
     * A vanity slug (e.g. `.../company/coolify`) has no numeric id and returns
     * null — those require the org-lookup API (`linkedin:org-lookup`).
     */
    public static function normalizeUrn(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (preg_match('/^urn:li:organization:(\d+)$/', $reference, $matches) === 1) {
            return 'urn:li:organization:'.$matches[1];
        }

        if (preg_match('/^\d+$/', $reference) === 1) {
            return 'urn:li:organization:'.$reference;
        }

        if (preg_match('#linkedin\.com/company/(\d+)#i', $reference, $matches) === 1) {
            return 'urn:li:organization:'.$matches[1];
        }

        return null;
    }

    /**
     * Extract a vanity slug from a company page URL or bare slug, or null when
     * the reference is numeric / already a URN.
     */
    public static function vanityName(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (preg_match('~linkedin\.com/company/([^/?#]+)~i', $reference, $matches) === 1) {
            $slug = $matches[1];

            return preg_match('/^\d+$/', $slug) === 1 ? null : $slug;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]*$/', $reference) === 1 && preg_match('/^\d+$/', $reference) !== 1) {
            return $reference;
        }

        return null;
    }

    public static function isOrgUrn(string $value): bool
    {
        return preg_match('/^urn:li:organization:\d+$/', trim($value)) === 1;
    }

    /**
     * Whether a value is an explicit LinkedIn org reference (a `urn:li:...`
     * string or a company page URL) rather than a plain display name.
     *
     * A bare number is deliberately excluded — it is far more likely to be
     * display text than an org id when typed into the LinkedIn name field.
     */
    public static function looksLikeReference(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match('/^urn:li:organization:\d+$/', $value) === 1
            || preg_match('~linkedin\.com/company/~i', $value) === 1;
    }
}
