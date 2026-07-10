# sidebar

2026-07-10 · golden pair via CLI + replayed customization · clean.

## Changed

- `resources/js/components/ui/sidebar.tsx` — installed the base-rhea variant (`Slot`/`asChild` → `useRender` on SidebarMenuButton etc.; depends on the already-migrated button/sheet/tooltip/separator wrappers). Then **replayed 3 project tweaks** `--overwrite` had reverted:
  - SidebarInset collapsed inset margin `…:ml-2` → `…:ml-0.5`.
  - SidebarGroupLabel: added `group-data-[collapsible=icon]:pointer-events-none`.
  - SidebarMenuButton cva: added `[&_svg]:pointer-events-none`.
- Consumers: `app-sidebar.tsx` (6×), `nav-user.tsx`, `workspace-selector.tsx` — `asChild`→`render` on SidebarMenuButton wrappers (no `nativeButton`; these use `useRender`, not the Button primitive). Leftover scan: clean.

## Left alone

- `use-mobile.ts` and `input.tsx` were pulled in by the CLI as sidebar deps and **reverted** — `use-mobile.ts` is a custom `useSyncExternalStore` hook and `input.tsx` is a plain native `<input>` (not Radix); the base-rhea versions would have regressed both. See project.md.

## Behavior changes

- None functional. Collapsed-icon svg is now pointer-inert (matches prior custom behavior).

## Verify by hand

- Expand/collapse the sidebar (icon mode), hover menu items, open the mobile sheet variant, and confirm the inset margin and tooltip-on-collapse behavior.
