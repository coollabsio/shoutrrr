<?php

declare(strict_types=1);

namespace App\Services\Legal;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes author-supplied rich text down to a strict allowlist before it is
 * stored and served on the public legal pages.
 *
 * The management UI authors content in a TipTap editor, but the editor is only
 * a convenience — anyone can POST arbitrary HTML to the update endpoint, so the
 * server is the real security boundary. Every submitted body runs through
 * Symfony's HtmlSanitizer with the default deny-all action: only the handful of
 * tags a legal document needs survive, links are restricted to safe schemes,
 * and every other element (script, style, iframe, media, event-handler
 * attributes, inline styles, …) is removed together with its contents. Combined
 * with the application's strict CSP this closes the stored-XSS vector while
 * still allowing rich, pasted documents.
 *
 * The allowlist mirrors exactly what the TipTap editor emits, so content
 * authored (or pasted, which TipTap normalizes client-side) in the editor is
 * never lost on the round-trip.
 */
class LegalHtmlSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $maxLength = (int) config('kit.legal.max_body_length');

        $config = (new HtmlSanitizerConfig)
            // Block-level structure.
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('hr')
            ->allowElement('blockquote')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('h5')
            ->allowElement('h6')
            // Inline emphasis (both the semantic and legacy tags, so pasted
            // formatting survives even when it bypasses the editor).
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('code')
            ->allowElement('pre')
            // Lists.
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            // Links: only href, only safe schemes, never relative, and always
            // rel-hardened. Everything else (target, style, on* handlers) is
            // stripped because it is not in the allowlist.
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->withMaxInputLength($maxLength > 0 ? $maxLength : 100_000);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Return the sanitized HTML, or null when the input carries no visible
     * content — so blank drafts (e.g. the editor's empty "<p></p>") persist as
     * null rather than an empty element.
     */
    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim($this->sanitizer->sanitize($html));

        return $this->hasVisibleText($clean) ? $clean : null;
    }

    private function hasVisibleText(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        // Treat non-breaking spaces as whitespace so an "empty" pasted line does
        // not count as content.
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim($text) !== '';
    }
}
