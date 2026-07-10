# tooltip

2026-07-10 · golden pair via CLI + replayed customization · clean (1 test rewritten).

## Changed

- `resources/js/components/ui/tooltip.tsx` — Radix `Tooltip` → Base UI `Tooltip` (`@base-ui/react/tooltip`). `Content`→`Portal>Positioner>Popup`, Provider `delayDuration`→`delay`. Then **replayed the project's `--tooltip-bg` customization** that `--overwrite` had destroyed:
  - Popup surface `bg-foreground` → `[--tooltip-bg:var(--foreground)] bg-(--tooltip-bg)`.
  - Arrow `bg-foreground fill-foreground` → `bg-(--tooltip-bg) fill-(--tooltip-bg)` (kept Base UI's rotated-`<div>` arrow shape).
  - This indirection is **load-bearing**: `target-status-chips.tsx` overrides `[--tooltip-bg:var(--popover)]` to recolor its failure tooltip. Without the replay that override silently no-oped.
- `resources/js/app.tsx` — `<TooltipProvider delayDuration={0}>` → `delay={0}`.
- `resources/js/components/ui/__tests__/tooltip.test.ts` — updated: `asChild`→`render`, dropped `forceMount`, retargeted arrow assertions to Base UI's `<div>` arrow (was `<svg>`) while keeping the `--tooltip-bg` contract. Passes.

## Left alone

- Non-Radix siblings untouched.

## Behavior changes

- **FLAG — hover-open feel.** Base UI hover/focus timing differs from Radix (Provider `delay` semantics; default `sideOffset` 0→4). Delay is pinned to 0 here so it should feel instant. Not patched further.
- **FLAG — jsdom cannot drive the popup open.** Base UI's tooltip opens through floating-ui interactions that jsdom doesn't simulate (no `@testing-library/user-event` in the repo). `target-status-chips.test.ts` was rewritten to assert the failure-message formatting against the always-rendered trigger and the readable styling at the source level; the open-on-hover/focus itself is browser-verified. See `.migration/target-status-chips-consumer.md` note in project.md.

## Verify by hand

- Hover and keyboard-focus a tooltip trigger; confirm it opens instantly, the arrow color matches the surface, and the failure-status tooltip in the composer renders wrapped, readable text on `[--tooltip-bg:var(--popover)]`.
