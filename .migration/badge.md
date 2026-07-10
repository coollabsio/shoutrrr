# badge

2026-07-10 · golden pair via CLI + replayed customization · clean.

## Changed

- `resources/js/components/ui/badge.tsx` — installed the base-rhea variant (`Slot`/`asChild` → `useRender`), then **replayed the project's 3 custom cva variants** that `--overwrite` would have destroyed: `info` (`bg-blue-500/10 …`), `success` (`bg-emerald-500/10 …`), `warning` (`bg-amber-500/10 …`), inserted after `outline`. Leftover scan: clean.

## Left alone

- Non-Radix siblings untouched.

## Behavior changes

- None. Badge only used Radix `Slot` for `asChild`; now `useRender`. The 3 custom variants are preserved exactly.

## Verify by hand

- Render an info/success/warning badge; confirm the colors match pre-migration in light and dark.
- Any `<Badge asChild>` (rendered as a link) still merges its element correctly via `render`.
