# select

2026-07-10 · golden pair via CLI (`shadcn add select --overwrite`, style `base-rhea`) · clean.

## Changed

- `resources/js/components/ui/select.tsx` — Radix `Select` → Base UI `Select` (`@base-ui/react/select`). `Viewport`→`List`, `ScrollUp/DownButton`→`ScrollUp/DownArrow`, `Label`→`GroupLabel`, Icon/ItemIndicator `asChild`→`render`. `position` prop dropped in favor of `alignItemWithTrigger` on the Positioner. Root is a bare re-export (`SelectPrimitive.Root.Props` is generic). CLI reproduced the translucent menu + lucide resolution. Leftover scan: clean.
- Consumers: `reply-filters.tsx` — `onValueChange` now yields `string | null`; the two handlers were hardened (`!v || v === 'all' ? '' : v`) so a null value can't leak into the router payload.

## Left alone

- Non-Radix siblings untouched.

## Behavior changes

- **FLAG — `onValueChange` widened** from `(value: string)` to `(value: Value | null, eventDetails)`. Any `useState<string>` + `onValueChange={setState}` would break; consumers here were adjusted. New Select code should type state as `string | null` or coerce.
- Collision/scroll defaults follow Base UI (`collisionPadding` 0→5, etc.).

## Verify by hand

- Open each Select (platform/account filters in engagement), pick items, confirm the trigger label updates and the "all" reset clears the filter.
- Keyboard: open with Enter/Space, arrow + typeahead, Escape.
