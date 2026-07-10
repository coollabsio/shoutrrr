# Project migration: Radix UI → Base UI

2026-07-10 · whole-project · branch `feat/migrate-radix-to-base` · **green** (tsc 0 errors, oxlint clean, `bun run build` ✓, 433 vitest pass).

## Strategy

shadcn project, style `radix-rhea` → `base-rhea` (prefixed style ⇒ golden-pair via the shadcn CLI). Installed `@base-ui/react@1.6.0` alongside Radix; flipped `components.json` style to `base-rhea`; migrated each wrapper, then swept consumers, then removed Radix.

## Wrappers (20)

`shadcn add <c> --overwrite` reproduced this project's exact resolution (lucide icons from `IconPlaceholder`, `inverted-translucent`/`subtle` menu expansion, `cn-font-heading`→`font-heading`, `@/lib/utils` alias, `"use client"` stripped) for the pristine-equivalent wrappers: alert-dialog, avatar, breadcrumb, button, checkbox, collapsible, dialog, dropdown-menu, label, navigation-menu, select, separator, sheet, toggle, toggle-group.

Wrappers with real customizations that `--overwrite` would have destroyed were handled specially (own report files): **badge** (+3 cva variants), **sidebar** (3 tweaks replayed), **tooltip** (`--tooltip-bg` indirection replayed), **switch** (hand-migrated to preserve its simpler custom styling), **popover** (wrapper extended to forward `anchor`).

Final leftover scan `grep -rn "radix-ui|@radix-ui|IconPlaceholder" resources/js/components/ui`: **clean**.

## Dependency swap

- Added `@base-ui/react@1.6.0`.
- Removed 15 direct Radix deps: `radix-ui` + `@radix-ui/react-{alert-dialog,avatar,checkbox,collapsible,dialog,dropdown-menu,label,navigation-menu,select,separator,slot,toggle,toggle-group,tooltip}`.
- Remaining `node_modules/@radix-ui/*` are **transitive** deps of `cmdk` (verified: `@radix-ui/react-dialog` ← cmdk@1.1.1) and are intentionally kept.

## App-code sweep

- **`asChild` → `render` (63 call sites, 42 files)** — mechanical `<W asChild><C/></W>` → `<W render={<C/>}>…</W>`, children hoisted. `nativeButton={false}` added where a `<Button>`/`*Trigger` renders an `<a>`/`<Link>` (13 sites). Dispatched across 6 parallel passes + hand-verified; all files scanned clean of `asChild`.
- **`PopoverAnchor` removed** — `editor-body.tsx`, `emoji-suggest-popover.tsx`: anchor `<div>` rendered directly, `anchor={ref}` passed to `PopoverContent` (wrapper extended). See popover.md.
- **Dialog/Popover dismiss callbacks** — `onOpenAutoFocus`→`initialFocus`, `onEscapeKeyDown`/`onFocusOutside`/`onPointerDownOutside` → Root `onOpenChange` reason + `eventDetails.cancel()`. `conflict-dialog.tsx`: the two `preventDefault` dismiss guards were removed (the dialog is fully controlled with no `onOpenChange`, so it can't self-dismiss anyway).
- **ToggleGroup value → arrays** — `calendar-header.tsx`, `published-post-view.tsx`, `reply-filters.tsx`: dropped `type="single"`, wrapped value in `[…]`, read `value[0]` in `onValueChange`.
- **Select `onValueChange` `| null`** — `reply-filters.tsx`: hardened the two handlers.
- **`TooltipProvider delayDuration` → `delay`** — `app.tsx`.
- **`command.tsx`** (cmdk, not Radix) — narrowed `CommandDialog` children type to `React.ReactNode` (Base UI `Dialog.Root` widened `children` to include a payload render fn).
- **`composer-toolbar.tsx`** — hand-rolled Radix `Popover` primitive (the emoji keep-warm picker): `radix-ui` → `@base-ui/react/popover`, `Content`→`Portal>Positioner>Popup`, `forceMount`→Portal `keepMounted`, `onOpenAutoFocus`→`initialFocus={false}`, `data-[state=…]`→`data-open`/`data-closed`, `--radix-…-transform-origin`→`--transform-origin`. The click-through-while-closed guard moved from an app.css `[data-radix-popper-content-wrapper]:has(…)` rule (Radix-specific, now dead — removed) to `data-closed:pointer-events-none` on the Positioner.

## Behavior deltas flagged (not patched)

- **dropdown-menu**: Base UI menu checkbox/radio items default `closeOnClick: false` (Radix closed the menu). See dropdown-menu.md.
- **select**: `onValueChange` widened to `(value | null, eventDetails)`. See select.md.
- **tooltip**: hover-open feel differs; jsdom can't drive the popup open (no `@testing-library/user-event`), so `target-status-chips.test.ts` was rewritten to cover the formatting via the always-rendered trigger + source-level readable-styling check. See tooltip.md.

## Tests

- Full vitest: 433 pass. The 4 remaining failures are **pre-existing on `main`** (verified by stashing): `pick-time.test.ts` (source-copy drift), `reconnect-oauth-route.test.ts` ×2, `analytics/index.test.ts` — none touched by this migration.
- `tooltip.test.ts` updated (Base UI `<div>` arrow + `--tooltip-bg` contract). `target-status-chips.test.ts` rewritten (see above).

## Post-migration runtime fixes (browser-found, tsc/build could not catch)

- **`MenuGroupContext is missing` crash** on opening the user menu / connect-account menu / posts filters. Base UI `Menu.GroupLabel` throws unless inside a `Menu.Group`/`RadioGroup`, but the app uses `DropdownMenuLabel` as a standalone section heading (Radix allowed this). Fix: `DropdownMenuLabel` now renders a plain styled `<div>` (its actual Radix behavior). Locked in by `dropdown-menu.test.ts` (renders an open menu with a standalone label + group + radio group, asserts no throw).
- **Compose button lost its green on `/dashboard`** (its own active page). Base UI `useRender` emits `state.active` as a **presence** attribute `data-active` (not `data-active="true"`), so `composeButtonClassName`'s `data-[active=true]:bg-primary` override stopped matching and the cva's `data-active:bg-sidebar-accent` won. Fix: `compose-nav.ts` now uses `data-active:bg-primary …`. (Other `data-[active=true]` consumers set their own boolean attribute and were left as-is.)

## Status: 5 wrappers remain on Radix → **0 wrappers remain on Radix.**
