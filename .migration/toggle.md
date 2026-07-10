# toggle

2026-07-10 · golden pair via CLI (`shadcn add toggle --overwrite` against style `base-rhea`) · clean.

## Changed

- `resources/js/components/ui/toggle.tsx` — replaced the Radix wrapper with the base-rhea variant. Primitive import now `@base-ui/react/…`; callable primitive. The CLI reproduced this project's exact resolution (lucide icons from `IconPlaceholder`, `menuColor`/`menuAccent` translucent expansion, `cn-font-heading`→`font-heading`, `@/lib/utils` alias, `"use client"` stripped). Leftover scan `grep -n "radix-ui\|@radix-ui\|IconPlaceholder"` on this file: clean.


## Left alone

- Non-Radix siblings untouched. `cmdk` (command), `sonner`, `input-otp`, `react-day-picker` (calendar), `recharts` (chart) are not Radix and keep their transitive `@radix-ui/*` deps (e.g. cmdk → `@radix-ui/react-dialog`).

## Behavior changes

- None specific to this wrapper. Base UI presence attributes (`data-open`/`data-closed`) replace `data-[state=…]`; the base-rhea classes already use them.

## Verify by hand

- Open/close the control in-app; confirm styling, focus ring, and keyboard interaction match the pre-migration look.
