# Plan: CodeRabbit PR #133 review fixes

Address the CodeRabbit findings on PR #133 (GIF/sticker/clip browser). Every
finding is fixed except two that were judged not worth doing (recorded at the
bottom). Tasks are grouped by file so no two tasks touch the same file.

## Global Constraints

- Tests use **Pest** (PHP) and **vitest + @testing-library/react** (JS). Never
  convert Pest tests to PHPUnit. Pest files under `tests/Feature` and
  `tests/Unit` need no `extends TestCase` / `use RefreshDatabase`.
- JS package manager is **bun**: `bunx vitest run <file>`, never npm/npx/pnpm.
- Lint is **oxlint** (`bun run lint:check`), format is **oxfmt**
  (`bun run format`). PHP style is **Pint** (`vendor/bin/pint --dirty --format agent`).
- Type-check with `bunx tsc --noEmit` after touching TS. PHP static analysis:
  `composer types:check` (Larastan level 7) — only when PHP changed.
- **No `useMemo` / `useCallback`** — this project relies on the React Compiler.
- File and folder names are **kebab-case**; component identifiers stay PascalCase.
- Follow existing conventions in sibling files. Do not add dependencies.
- Every task must add or update a test that fails before the fix and passes
  after it, and must report the exact command run plus its output.
- Commit your work with a conventional-commit message when the task is done.
- Do not touch files outside the ones your task names.

---

## Task 1: Fix GIF picker pagination stall, storage crash, ref-in-render, and tab semantics

**Files:** `resources/js/components/compose/gif-picker.tsx`,
`resources/js/components/compose/__tests__/gif-picker.test.tsx`

Four fixes in one file. Read the file first — it is heavily commented and the
comments explain why the current code is shaped the way it is. Keep those
comments accurate: update the prose where your change invalidates it.

### 1a. Pagination stalls after a catalog/query change made mid-flight (the important one)

`GifPicker` has a "settle-effect" that re-checks whether another page is needed
once a page finishes loading, because a short page never produces a fresh
IntersectionObserver crossing. It tracks `settledItemCountRef` — how many items
were on screen last time — and only calls `loadMore()` when the item count
actually grew (a guard against an API that reports `has_next: true` alongside an
empty page).

The shrink-resync branch is wrong:

```js
if (search.items.length < settledItemCountRef.current) {
    settledItemCountRef.current = search.items.length;
}
```

Failing sequence: the user is on page 2 of GIFs (`settledItemCountRef` = 48) and
clicks the Stickers tab **while a request is in flight**. The commit where
`items` resets to `[]` is skipped by the effect's `if (search.isLoading) return;`
guard, so the baseline stays at 48. Sticker page 1 then lands with 24 items: the
shrink branch resyncs the baseline to 24, and the progress check immediately
compares `24 > 24` → false → no `loadMore()`. If that page does not overflow the
420px scroll area the sentinel never re-crosses either, so pagination is dead
with `hasNext: true` and the user cannot scroll to force it.

Fix: on shrink, reset the baseline to `0`, so the whole new first page counts as
progress. The `intersectingRef` guard below already prevents a spurious fetch
when the new page does overflow.

Test: drive it through the public component. Stub `fetch` so page 1 of `gif`
returns items with `has_next: true` and pages for `sticker` do too; use the
existing `MockIntersectionObserver` in that test file to report the sentinel as
intersecting; switch catalogs while the first request is still in flight (resolve
it after the tab click), and assert a **page 2 request for the new catalog** is
made. The test must fail against the current `= search.items.length` code.

### 1b. `localStorage.setItem` is unguarded

`handleToggleFavorite` writes to `localStorage` straight out of a click handler.
Safari private mode and quota-exceeded both throw there, which would take the
picker down mid-interaction. The read path (`parseFavorites`) is already
defensive; make the write match — wrap in `try`/`catch` and keep the in-memory
state update. Add a test that stubs `localStorage.setItem` to throw and asserts
the favourite still toggles in the UI without the component crashing.

### 1c. Ref written during render

```js
const loadMoreRef = useRef(search.loadMore);
loadMoreRef.current = search.loadMore;
```

React Doctor flags this as an error and it opts the component out of React
Compiler memoization. Move the write into an effect with **no dependency array**
(runs after every commit), which keeps the observer callback — which only ever
fires post-commit — reading the same value. Keep the existing explanatory
comment about why the observer must not depend on `search`.

### 1d. `role="tablist"` without the keyboard pattern

The catalog switcher uses `role="tablist"` / `role="tab"` / `aria-selected` but
implements none of the APG tab pattern: no arrow-key roving focus, no
`aria-controls`, no `tabpanel`. Every tab is its own tab stop. Drop the tab roles
and expose the state honestly as a group of toggle buttons: keep a wrapping
`role="group"` with an `aria-label` (e.g. "Catalog"), and give each button
`aria-pressed={catalog === entry}`.

This changes queries in the existing tests — `screen.getByRole('tab', { name: /stickers/i })`
appears in several of them. Update every affected query in that test file (and
only in that file) to the new semantics.

---

## Task 2: Reveal cache-hit GIF images that never fire `onLoad`

**Files:** `resources/js/components/compose/gif-tile.tsx`,
`resources/js/components/compose/__tests__/gif-tile.test.tsx`

The still-image branch of `GifTile` renders `<img>` with `className="... opacity-0 ..."`
and only removes `opacity-0` from its `onLoad` handler. When the browser serves
the image from cache, `load` can fire before React attaches the listener, and the
tile then renders a transparent box forever with no self-recovery. The favourites
shelf re-renders the exact same CDN URLs, so cache hits are the normal path here.

Fix: also reveal the image when it is already complete on mount — check
`node.complete` in a ref callback (`ref={(node) => { if (node?.complete) … }}`) and
remove the `opacity-0` class there too, keeping the existing `onLoad` fade for
the normal path. Match the file's existing style.

Test: render a tile whose `<img>` reports `complete: true` before any `load`
event fires (define the `complete` property on `HTMLImageElement.prototype` for
the test, or on the node) and assert the rendered image does **not** carry the
`opacity-0` class. Also keep a test proving the `onLoad` path still reveals a
non-cached image.

---

## Task 3: Stop `isLoading` sticking when the GIF search hook is disabled mid-flight

**Files:** `resources/js/hooks/compose/use-gif-search.ts`,
`resources/js/hooks/compose/__tests__/use-gif-search.test.ts`

`useGifSearch(catalog, enabled)` sets `isLoading` true when it starts a fetch. If
`enabled` flips `true → false` while a request is in flight, the effect cleanup
aborts the request (so the `.finally()` bails out on `signal.aborted`) and the
re-run returns early at `if (!enabled) return;` without touching `isLoading` — so
any consumer that keeps the hook mounted and toggles `enabled` off renders a
permanent loader. `GifPicker` passes a literal `true` today, so this is latent,
but the parameter exists precisely so callers can toggle it.

Fix: when the effect runs with `enabled === false`, clear the loading flag (and
make sure an in-flight request that resolves after that cannot set it back).
Keep the existing request-supersession (`requestId`) and abort handling intact.

Test: render the hook with `enabled: true` and a `fetch` that never resolves,
re-render with `enabled: false`, and assert `isLoading` is `false`.

---

## Task 4: Keep a visible error chip when a GIF attach fails

**Files:** `resources/js/hooks/compose/use-media-uploads.ts`,
`resources/js/hooks/compose/__tests__/use-media-uploads.test.ts` (create it if it
does not exist; check for an existing suite for this hook first and extend that
instead)

`trackPending()` shows a pending chip while the server downloads a remote GIF or
clip, then adds the resolved media. Its `finally` block calls
`dismissPending(tempId)` on **every** path — including failure. The sibling
`uploadImage` / `uploadVideo` paths instead call `failUpload(tempId)`, which
leaves an `'error'`-status chip the user can see and dismiss themselves. As
written, a failed GIF attach shows a toast and then the chip silently vanishes;
if the user looked away there is no evidence anything failed.

Fix: on success, drop the chip as today. On failure, keep the toast **and** call
the existing `failUpload(tempId)` so the chip stays with `status: 'error'`.

Test: make the `work()` callback reject and assert the pending entry survives
with `status: 'error'`; assert the success path still removes the chip.

---

## Task 5: Bracket IPv6 addresses in the SSRF connection pin

**Files:** `app/Support/SafeVideoFetcher.php`, `app/Support/SafeImageFetcher.php`,
`tests/Feature/Gifs/SafeVideoFetcherTest.php`, plus the existing
`SafeImageFetcher` test file (find it) — and a new shared helper if you extract one.

Both fetchers pin curl to a pre-validated IP to close the DNS-rebinding
time-of-check/time-of-use gap, using:

```php
return [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ips[0])]];
```

`CURLOPT_RESOLVE` entries are `HOST:PORT:ADDRESS`, and an IPv6 address must be
written in **brackets** (`example.com:443:[2606:4700::1111]`). Unbracketed, the
entry is unparseable, curl ignores the pin and falls back to its own DNS
resolution — silently voiding the rebinding protection both classes document in
their docblocks. `SafeVideoFetcher::resolveAaaa()` explicitly collects AAAA
records, so an IPv6 address genuinely can reach `$ips[0]`.

Fix: bracket the address when it is IPv6 (`filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)`),
leave IPv4 untouched. The two classes carry byte-identical `pinnedResolution()`
implementations — extract the shared logic (a trait or small support class under
`app/Support/`) and have both use it, rather than fixing the same bug twice.
Keep both classes' documented behaviour identical to today apart from the
bracketing.

Test (Pest): assert the built `CURLOPT_RESOLVE` entry for an IPv6 address is
`host:port:[addr]` and for IPv4 stays `host:port:addr`. If `pinnedResolution()`
is protected, exercise it the way the existing tests exercise these classes
(check them first) — a small subclass or reflection is acceptable if that is
already the local idiom; otherwise test through the shared helper's public API.

---

## Task 6: Tidy the GIF backend entry points

**Files:** `app/Http/Middleware/EnsureGifsEnabled.php`,
`app/Http/Controllers/Gifs/GifBrowserController.php`, `routes/web.php`,
`tests/Feature/Gifs/GifBrowserControllerTest.php`

Two small cleanups.

**6a.** `EnsureGifsEnabled::handle()` reaches for `app(KlipyClient::class)` while
`GifBrowserController` — added in the same PR — injects `KlipyClient` through
constructor promotion. Make the middleware consistent: promote a
`private readonly KlipyClient $klipy` constructor property and use it. The
project's PHP guidelines require constructor property promotion.

**6b.** `GifBrowserController::index()` and `::recent()` both repeat
`abort_unless(in_array($catalog, KlipyClient::CATALOGS, true), 404);`. Move that
to the route definitions in `routes/web.php` as
`->whereIn('catalog', KlipyClient::CATALOGS)` on both GIF routes and drop the
duplicated guard from the controller, so an unknown catalog 404s before the
controller runs and the allowed set lives in one place.

Verify the existing feature test that asserts an unknown catalog returns 404
still passes (it must — the status is unchanged); add one if none exists for the
`recent` route.

---

## Task 7: Validate GIF media-mixing against the client-declared draft set

**Files:** `app/Http/Controllers/Gifs/PostGifController.php`,
`app/Http/Requests/Gifs/AttachGifRequest.php`,
`resources/js/components/compose/composer.tsx`,
`tests/Feature/Gifs/AttachGifEndpointTest.php`

`GifAttacher::guardMediaRules()` rejects invalid combinations (a clip cannot join
any other media; a GIF cannot join other media; existing video blocks images).
`PostGifController` feeds it `$post->media()->get()` — but composer media rows are
created as orphans with `post_id` null until `DraftService::attachMedia()`
associates them on the next draft save, so for an unsaved draft that relation is
empty and the guard sees nothing. A user can therefore attach a GIF onto a draft
that already holds a video.

`ReplyGifController` already solves exactly this: the client sends the media ids
it currently holds, and the controller resolves them **scoped to the workspace**
and authorizes them before passing the rows to the attacher. Read
`ReplyGifController` and `AttachGifRequest` first and mirror that pattern.

Fix:

1. Accept an optional `media_ids` array on the post attach request (same
   validation shape the reply path uses).
2. In `PostGifController`, resolve those ids scoped to `$post->workspace_id`
   (never trust the client's ids alone), merge them with `$post->media()->get()`,
   de-duplicate by id, and pass the combined set to `GifAttacher::attach()`.
3. In `composer.tsx`, send the composer's current media ids with the attach
   request, the way the reply box does.

Tests (Pest, extend the existing endpoint test file): attaching a GIF is rejected
with 422 when the client declares a draft video's media id; a media id belonging
to **another workspace** is ignored/rejected rather than trusted; the happy path
still returns 201.

---

## Task 8: Extract the duplicated GIF-attach request

**Files:** new `resources/js/lib/compose/gifs/attach.ts` (kebab-case; pick the
name that matches sibling files in that directory),
`resources/js/components/compose/composer.tsx`,
`resources/js/pages/engagement/components/use-reply-media.tsx`, plus a new unit
test for the helper and the existing suites for both call sites.

**Do this task after Task 7** — Task 7 touches the same `composer.tsx` function.

Both surfaces hand-roll the identical POST: same headers block, same
`xsrfHeader()` spread, same `response.json().catch(() => ({}))` error unwrap, same
`'That GIF could not be attached.'` fallback message, same `{ media: MediaView }`
return shape. Only the endpoint URL and the extra `media_ids` field differ. These
will drift.

Fix: extract one helper (e.g. `postGifAttachment(url, payload)`) into
`resources/js/lib/compose/gifs/`, returning the parsed `MediaView` and throwing an
`Error` carrying the server's message (falling back to the shared copy). Use it
from both call sites with no behaviour change.

Also in `composer.tsx`: the local `const post = await ensurePost()` shadows the
outer `post` prop, which is a `PostView | null`, while the local is a post **id
string**. Rename the local to `postId` and update its uses.

Test: unit-test the helper for the success shape, the server-message error, and
the fallback message. Re-run the existing composer and reply-media suites to
prove both call sites still behave the same.

---

## Task 9: De-duplicate the popover tooltip wrapper

**Files:** `resources/js/components/compose/emoji-popover.tsx`,
`resources/js/components/compose/gif-popover.tsx`, a new small shared component
under `resources/js/components/compose/`, and the existing tests for both.

`EmojiPopover` and `GifPopover` now carry a byte-identical block: an optional
`tooltip` prop that, when present, wraps the popover trigger in
`<Tooltip disabled={open}>` + `<TooltipTrigger render={…} />` + `<TooltipContent side={side}>`,
and renders the plain trigger otherwise (including the identical explanatory
comment).

Fix: extract that into one shared component (e.g. `PopoverTriggerWithTooltip`)
that both popovers use, preserving the current behaviour exactly — including
`disabled={open}` so the tooltip cannot appear over an open popover, the
`side` passthrough, and the untooltipped fall-through path.

Do **not** extract the trigger `<button>` markup itself; the composer toolbar and
reply box deliberately style their triggers differently.

Test: cover both branches (with and without `tooltip`) of the shared component,
and re-run the existing composer-toolbar and quick-reply-box suites.

---

## Task 10: Test-suite hygiene

**Files:** `resources/js/lib/compose/gifs/__tests__/favorites.test.ts`,
`resources/js/pages/engagement/components/use-reply-media.test.ts`, new shared
test util (kebab-case) for the IntersectionObserver mock,
`resources/js/components/compose/__tests__/gif-picker.test.tsx`,
`resources/js/components/compose/__tests__/gif-tile.test.tsx`

**Do this task last** — Tasks 1 and 2 change the two test files it touches.

**10a.** In `favorites.test.ts`, the case named `'dedupes by slug across catalogs'`
asserts `toHaveLength(2)` — i.e. it proves the exact opposite: `toggleFavorite`
keys on `catalog:slug`, so the same slug is kept separately per catalog. Rename
the test to state what it actually verifies. Do not change the assertion or the
implementation.

**10b.** `use-reply-media.test.ts` exercises only happy paths for GIF attach. Add
error-path coverage: a failed attach request surfaces the server's error message
and leaves the reply media state consistent (mirror what Task 4 establishes for
the composer's pending chips — read the current hook to see what "consistent"
means there; do not change the hook in this task).

**10c.** `MockIntersectionObserver` — the same class, `trigger()` helper and
`instances` array — is copy-pasted into both `gif-picker.test.tsx` and
`gif-tile.test.tsx`. Extract it to one shared test utility module and import it
in both suites. Keep the behaviour identical; both suites must still pass.

---

## Deliberately not done

- **Shared `PopoverIconTrigger` for the emoji/GIF trigger buttons across
  `composer-toolbar.tsx` and `quick-reply-box.tsx`** — the two surfaces style
  their triggers differently on purpose (bordered toolbar chip vs. ghost button
  with disabled states). A shared component would have to take the className as a
  prop, at which point it is a `<button>` with extra steps. Two call sites do not
  justify it.
- **Rewriting draft media persistence so the server owns the full draft set** —
  CodeRabbit's underlying observation is right (orphan `post_id` rows), but that
  is how the entire media pipeline works for plain file uploads too, not
  something this PR introduced. Task 7 closes the GIF-specific gap using the
  pattern the reply path already established; re-architecting draft media
  ownership is a separate change.
