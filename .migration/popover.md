# popover

2026-07-10 · golden pair via CLI + wrapper extension + hand-migrated consumers · clean.

## Changed

- `resources/js/components/ui/popover.tsx` — Radix `Popover` → Base UI `Popover` (`@base-ui/react/popover`). `Content`→`Portal>Positioner>Popup`; positioning props forwarded to the Positioner. **Extended the wrapper**: `PopoverContent` now also picks and forwards `anchor` to the Positioner (Base UI dropped the `Anchor` part; the Positioner's `anchor` prop replaces it). Two consumers need this.
- `resources/js/components/compose/emoji-suggest-popover.tsx` — dropped `PopoverAnchor`; the zero-size anchor `<div>` is rendered directly and passed via `anchor={anchorRef}`. `onOpenAutoFocus={preventDefault}` → `initialFocus={false}`. `onFocusOutside={preventDefault}` → handled in Root `onOpenChange` (`reason === 'focus-out'` ⇒ `eventDetails.cancel()`).
- `resources/js/components/compose/editor-body.tsx` — same `PopoverAnchor` removal + `anchor` for the mention picker. `onOpenAutoFocus`→`initialFocus={false}`; `onEscapeKeyDown` (which called `handleMentionEscape()`) → Root `onOpenChange` (`reason === 'escape-key'` ⇒ `cancel()` + `handleMentionEscape()`).
- `resources/js/components/compose/composer-toolbar.tsx` — a **hand-rolled Radix Popover** (the keep-warm emoji picker) migrated separately; see its report entry in project.md.

## Left alone

- Non-Radix siblings untouched.

## Behavior changes

- Per-interaction dismiss callbacks (`onEscapeKeyDown`/`onPointerDownOutside`/`onFocusOutside`) no longer exist as props — they're now reasons on Root `onOpenChange` + `eventDetails.cancel()`. Semantics preserved for the two composer popovers.
- Base UI default `collisionPadding` 0→5, `arrowPadding` 0→5.

## Verify by hand

- Emoji `:shortcode` typeahead and the `@mention` picker: confirm the popover anchors to the caret position, focus stays in the editor, Escape/click-away dismiss correctly, and selection inserts.
