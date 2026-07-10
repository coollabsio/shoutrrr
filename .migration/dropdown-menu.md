# dropdown-menu

2026-07-10 · golden pair via CLI (`shadcn add dropdown-menu --overwrite`, style `base-rhea`) · clean.

## Changed

- `resources/js/components/ui/dropdown-menu.tsx` — Radix `DropdownMenu` → Base UI `Menu` (`@base-ui/react/menu`). Canonical menu remap: `Content`→`Portal>Positioner>Popup`, `Label`→`GroupLabel`, `ItemIndicator`→`CheckboxItemIndicator`/`RadioItemIndicator`, `Sub`→`SubmenuRoot`, `SubTrigger`→`SubmenuTrigger`. The CLI reproduced the project's `inverted-translucent` menu expansion (`bg-popover/70 backdrop-blur-2xl …`) and lucide icon resolution verbatim. Leftover scan: clean.
- Consumers: every `DropdownMenuTrigger asChild` / `DropdownMenuItem asChild` → `render={<…/>}` (see project.md). `DropdownMenuItem`→`<a>`/`<Link>` cases did **not** get `nativeButton` (Item is neither Button nor a Trigger).

## Left alone

- Non-Radix siblings untouched. `cmdk` keeps its transitive `@radix-ui/react-dialog`.

## Behavior changes

- **FLAG — checkbox/radio items no longer close the menu on click.** Base UI `Menu.CheckboxItem` / `Menu.RadioItem` default `closeOnClick: false` (Radix closed the menu). Not patched. If a consumer relies on close-on-select for a checkbox/radio item, add `closeOnClick` explicitly. Plain `DropdownMenuItem` still closes on click.
- SubTrigger open styling is now `data-popup-open:*` (was `data-[state=open]`).

## Verify by hand

- Open a dropdown, arrow-key + typeahead navigation, submenu open/close, Escape to dismiss.
- If any menu uses checkbox/radio items, confirm the intended close-vs-stay-open behavior on select.
