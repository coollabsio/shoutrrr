# dialog

2026-07-10 · golden pair via CLI (`shadcn add dialog --overwrite` against style `base-rhea`) · clean.

## Changed

- `resources/js/components/ui/dialog.tsx` — replaced the Radix wrapper with the base-rhea variant. Primitive import now `@base-ui/react/…`; `Overlay`→`Backdrop`, `Content`→`Popup` (centered modal, no Positioner), `Close` kept. The CLI reproduced this project's exact resolution (lucide icons from `IconPlaceholder`, `menuColor`/`menuAccent` translucent expansion, `cn-font-heading`→`font-heading`, `@/lib/utils` alias, `"use client"` stripped). Leftover scan `grep -n "radix-ui\|@radix-ui\|IconPlaceholder"` on this file: clean.
- Consumers: `DialogTrigger`/`DialogClose asChild`→`render`. `conflict-dialog.tsx` dismissal handlers restructured — see project.md.

## Left alone

- Non-Radix siblings untouched. `cmdk` (command), `sonner`, `input-otp`, `react-day-picker` (calendar), `recharts` (chart) are not Radix and keep their transitive `@radix-ui/*` deps (e.g. cmdk → `@radix-ui/react-dialog`).

## Behavior changes

- None specific to this wrapper. Base UI presence attributes (`data-open`/`data-closed`) replace `data-[state=…]`; the base-rhea classes already use them.

## Verify by hand

- Open/close the control in-app; confirm styling, focus ring, and keyboard interaction match the pre-migration look.
