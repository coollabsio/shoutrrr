# switch

2026-07-10 · transformation engine (hand-migrated the project's own file) · clean.

## Changed

- `resources/js/components/ui/switch.tsx` — the project's switch is a **simpler, customized** variant than the current base-rhea golden (no `size` prop, `rounded-full`, `h-5 w-9`, `translate-x-4`). `--overwrite` was deliberately NOT used (it would have imposed the golden's size-prop redesign and restyled the control). Instead a pure primitive swap:
  - `import { Switch as SwitchPrimitive } from "radix-ui"` → `from "@base-ui/react/switch"`.
  - `React.ComponentProps<typeof SwitchPrimitive.Root>` → `SwitchPrimitive.Root.Props`; dropped the now-unused `import * as React`.
  - Classes untouched — they already used Base UI's native `data-checked`/`data-unchecked` attributes, so the look is byte-for-byte preserved.

## Left alone

- The golden base-rhea switch design (size prop, `rounded-2xl`, thumb `translate-x-[calc(100%-4px)]`) was intentionally not adopted — the app never used it.

## Behavior changes

- None. Base UI Switch emits the same `data-checked`/`data-unchecked` the classes already targeted.

## Verify by hand

- Toggle a switch; confirm the thumb travel, on/off colors, and disabled state look identical to before.
