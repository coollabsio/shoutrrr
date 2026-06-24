# Screenshot Beautifier — Design

**Date:** 2026-06-24
**Status:** Approved, ready for planning
**Area:** Composer media (`resources/js/components/compose/*`, `app/Http/Controllers/Posts/*`)

## Summary

A Picyard/Pika-style screenshot beautifier integrated into the composer. When attaching
an image, the user can open an editor that places the screenshot on a predefined gradient
background with padding, rounded corners, shadow, a chosen aspect ratio, and a 3D tilt, then
crop it. Applying composes the result to a PNG, uploads it through a dedicated endpoint, and
attaches it as the post's media. The original source image and edit settings are persisted so
the beautified image can be re-edited non-destructively at any time.

This is v1. Gradient presets are the only background source for now; the design leaves a typed
seam for additional background sources to be added later.

## Goals

- Beautify an attached screenshot: gradient background, padding/inset, rounded corners, shadow,
  canvas aspect ratio, 3D tilt, and freeform crop (with ratio-lock presets).
- Integrate into the existing composer media flow; the composed image becomes the post's media.
- Persist the original source + edit settings so re-editing is non-destructive and survives reloads.

## Non-goals (v1)

- Non-gradient backgrounds (solid colors, uploaded images, wallpapers, browser frames) — the
  `BackgroundFill` type is structured so these slot in later without reworking the editor.
- Text/annotations, multi-image collages, watermarks.
- Beautifying video.

## User flows

**Beautify a new image**
1. User clicks the **Beautify** button in the composer toolbar (beside "Media").
2. An image-only file picker opens; on pick, the editor modal opens with that local `File` as the source.
3. User adjusts background, padding, radius, shadow, aspect, tilt, and optionally crops.
4. **Apply** rasterizes the stage to a PNG, uploads the composed PNG **and** the source image
   plus the settings JSON, and attaches the composed image to the post. Modal closes.

**Re-edit a beautified image**
1. An attached image that carries `edit_settings` shows an **Edit** affordance on its chip.
2. Clicking it opens the editor rehydrated from `source_url` + `edit_settings` (re-composing from
   the clean source — background is never double-applied).
3. **Apply** replaces the composed file + settings on the same `PostMedia`; the source is retained.

Plain "Media" upload is unchanged for images the user does not want to beautify.

## Rendering model (Path 1: CSS 3D + html-to-image)

Canvas 2D cannot do perspective, so the editor renders a **styled DOM stage** instead and
rasterizes that DOM for export.

- `screenshot-stage.tsx` renders the composition with DOM/CSS: an outer background element
  (CSS gradient) wrapping the screenshot `<img>` with padding, `border-radius`, `box-shadow`,
  and `transform: perspective(...) rotateX(...) rotateY(...)` for the tilt.
- The **same** stage node is the export surface. On Apply, `html-to-image` rasterizes the node
  to a PNG blob at an export scale (device-pixel-ratio-aware), with the longest edge capped at
  ~2048px to keep file size within platform media limits. WYSIWYG by construction.
- Crop is applied by pre-cropping the source to a blob (offscreen canvas) and feeding that to the
  stage, so the stage always renders a plain rectangular image. The crop overlay operates on the
  raw source in a separate crop mode.
- Sources are local object-URLs (fresh file) or same-origin public-disk URLs (re-edit), so the
  rasterizer never taints.

**New dependency (approved):** `html-to-image`.

## Edit settings contract

The single source of truth for a composition. Held in editor state, persisted as JSON on
`post_media.edit_settings`, and rehydrated on re-edit.

```ts
type BackgroundFill =
  | { type: 'gradient'; id: string; angle: number; stops: Array<{ color: string; at: number }> };
  // future: { type: 'solid' | 'image' | ... }

type ShadowPreset = 'none' | 'soft' | 'medium' | 'strong';
type AspectPreset = 'auto' | '1:1' | '4:3' | '3:4' | '16:9' | '9:16';

type EditSettings = {
  version: 1;
  background: BackgroundFill;
  padding: number;       // px in stage-space
  radius: number;        // px
  shadow: ShadowPreset;
  aspect: AspectPreset;
  tilt: { rotateX: number; rotateY: number }; // degrees
  crop: { x: number; y: number; width: number; height: number } | null; // source pixels
};
```

`settings.ts` owns `EditSettings`, the default settings, and a `normalize()` that validates and
fills an unknown/partial object on rehydration (tolerant to forward-compatible `version` drift).

## Component & module breakdown

Each unit has one purpose, a clear interface, and is independently testable.

**Frontend — pure/logic (Vitest)**
- `lib/screenshot/gradients.ts` — predefined gradient presets and the `BackgroundFill` union.
  Future background sources are added as new union variants here.
- `lib/screenshot/settings.ts` — `EditSettings` type, `defaultSettings()`, `normalize(raw)`.
- `lib/screenshot/layout.ts` — pure geometry: aspect → stage dimensions, crop-rect clamping,
  ratio-lock math for the crop overlay.

**Frontend — thin adapters**
- `lib/screenshot/crop.ts` — `cropToBlob(source, rect)` via offscreen canvas.
- `lib/screenshot/export.ts` — `rasterizeStage(node, { scale, maxEdge })` wrapping `html-to-image`.

**Frontend — UI**
- `components/compose/screenshot-editor.tsx` — the Dialog modal; owns `EditSettings` state and
  the controls panel; orchestrates crop → compose → upload.
- `components/compose/screenshot-stage.tsx` — the styled DOM composition (shared by live preview
  and export rasterization).
- `components/compose/crop-overlay.tsx` — 8-handle freeform crop with ratio-lock; emits a crop
  rect in source pixels.
- `hooks/compose/use-screenshot.ts` — uploads the composed + source files and settings to the
  new endpoint (store vs. update), integrating with the existing pending-chip lifecycle.

**Frontend — integration points**
- `components/compose/composer-toolbar.tsx` — add the **Beautify** `EToolButton`.
- `components/compose/media-chips.tsx` — add an **Edit** affordance on chips whose media carries
  `edit_settings`.
- `types/compose.ts` — extend `MediaView` with `edit_settings: EditSettings | null` and
  `source_url: string | null`.

**Backend (Laravel)**
- Migration `add_screenshot_fields_to_post_media`: add nullable `source_disk` (string),
  `source_path` (string), `edit_settings` (json) to `post_media`.
- `App\Models\PostMedia`: cast `edit_settings` to array, add a `source_url()` accessor.
- `App\Services\Posts\MediaStorageService`:
  - `storeBeautified($workspaceId, UploadedFile $composed, UploadedFile $source, array $settings): PostMedia`
  - `replaceBeautified(PostMedia $media, UploadedFile $composed, array $settings): PostMedia`
    (deletes the old composed file, keeps the source).
- `App\Http\Controllers\Posts\PostScreenshotController`:
  - `store(StorePostScreenshotRequest, Post $post)` — guards `status->isEditable()`, creates a
    `PostMedia` via `storeBeautified`, returns the MediaView JSON (incl. `edit_settings`, `source_url`).
  - `update(UpdatePostScreenshotRequest, Post $post, PostMedia $media)` — workspace-scoped, editable
    guard, `replaceBeautified`, returns updated MediaView.
- `StorePostScreenshotRequest` / `UpdatePostScreenshotRequest` — validate `composed` (image),
  `source` (image, store-only), and `settings` (JSON shape).
- Routes for store/update; Wayfinder regenerated with `--with-form`.
- Update every place that serializes a `MediaView` to include `edit_settings` + `source_url`
  (the inline array in `PostMediaController` and the central PostView media serializer).

## Data flow

```
Beautify button / Edit chip
        │ (File source  |  source_url + edit_settings)
        ▼
screenshot-editor (EditSettings state)
        │ live render
        ▼
screenshot-stage (DOM)  ──crop mode──►  crop-overlay → crop rect → crop.ts → cropped blob
        │ Apply
        ▼
export.ts rasterizeStage(node) → composed PNG blob
        │  composed + source(File) + settings
        ▼
use-screenshot → POST/PUT PostScreenshotController
        │ stores both files + settings (MediaStorageService)
        ▼
MediaView (with edit_settings, source_url) → onAddMedia → composer media
```

## Error handling

- File picker restricted to images; non-image rejected with a toast (mirror existing media UX).
- Rasterization failure (`html-to-image` throws) → toast, keep the modal open, no upload.
- Upload failure → reuse the pending-chip error state and toast already used by `useMediaUploads`.
- `store`/`update` enforce `post->status->isEditable()` (422) and workspace scoping (404),
  matching `PostMediaController`.
- `normalize()` defends rehydration against malformed/old `edit_settings`.

## Testing

**Vitest (pure logic)**
- `layout.test.ts` — aspect → stage dimensions for each preset; "Auto" matches source ratio;
  crop clamping never exceeds source bounds; ratio-lock yields locked dimensions.
- `gradients.test.ts` — every preset structurally valid (≥2 stops, in-range angle), unique ids.
- `settings.test.ts` — `defaultSettings()` shape; `normalize()` fills partials, rejects garbage,
  tolerates version drift.

**Pest (feature)**
- `PostScreenshotController@store` — stores composed + source files, persists settings, returns
  `edit_settings` + `source_url`; rejects non-editable post (422); workspace authz (404).
- `PostScreenshotController@update` — replaces composed file + settings, retains source; editable
  guard; cross-workspace media rejected (404).

The CSS stage and `html-to-image` rasterization are verified by eye (jsdom has no real layout/
canvas); all testable logic is isolated into the pure modules above.

## Risks & mitigations

- **Preview vs. export drift** (shadow/gradient banding under `html-to-image`): acceptable for v1;
  mitigated by exporting the exact same node that is previewed.
- **Export file size** from large screenshots + upscaling: cap the longest edge (~2048px) and use
  PNG; revisit JPEG/quality if platform limits bite.
- **Double storage** (source + composed per beautified media): accepted; necessary for
  non-destructive re-edit.
