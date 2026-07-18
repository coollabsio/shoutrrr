<?php

declare(strict_types=1);

namespace App\Services\Legal;

use Illuminate\Support\Str;

/**
 * Converts an owner-authored Markdown document into HTML that is safe to serve
 * on the public legal pages.
 *
 * Legal content is authored by workspace members but rendered to anonymous
 * visitors, so it is treated as untrusted for the purpose of output. All raw
 * HTML in the source is escaped and unsafe link schemes are dropped, which —
 * together with the application's strict, nonce-based CSP — removes the
 * stored-XSS vector while still allowing rich, structured documents.
 */
class LegalPageRenderer
{
    public function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return Str::markdown($markdown, [
            // Escape (never render) any raw HTML embedded in the source. This is
            // the primary defence: author markup can add no tags of its own, so
            // it can inject neither <script> nor event-handler attributes.
            'html_input' => 'escape',
            // Strip javascript:, data:, vbscript: and similar schemes from link
            // and image URLs.
            'allow_unsafe_links' => false,
        ]);
    }
}
