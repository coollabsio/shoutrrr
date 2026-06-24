# Screenshot Beautifier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Picyard-style screenshot beautifier to the composer — place a screenshot on a gradient background with padding, rounded corners, shadow, aspect ratio, 3D tilt, and crop; the composed PNG becomes the post's media, with the source + settings persisted for non-destructive re-editing.

**Architecture:** The editor renders a styled DOM "stage" (CSS gradient + transformed `<img>`), and `html-to-image` rasterizes that exact node to a PNG on Apply (WYSIWYG). All testable logic lives in pure `.ts` modules (gradients, settings, layout geometry, export-scale math); React components and canvas adapters are thin and verified by `tsc` + by eye. The composed PNG and the original source image are uploaded to a new `PostScreenshotController`, which stores both files plus the settings JSON on one `PostMedia` row.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest 4, Inertia v3 + React 19, TypeScript, Vitest (node env), Tailwind v4, `html-to-image` (new dependency), bun.

## Global Constraints

- **JS package manager is bun** — `bun add`, `bun run`, `bunx`. Never npm/pnpm/yarn.
- **JS tests are Vitest in `node` environment**, include glob `resources/js/**/*.test.ts` (`.test.ts` only — **no `.tsx` tests**, no DOM, no canvas, no testing-library). Only pure logic is unit-tested. Run: `bun run test`.
- **PHP tests are Pest** (`test()`/`expect()`), bound to `Tests\TestCase` + `RefreshDatabase` in `Feature/`. Run: `php artisan test --compact --filter=<name>`.
- **PHP files** start with `declare(strict_types=1);`, use constructor property promotion, explicit return types and param type hints, curly braces always, PHPDoc array shapes.
- **After PHP changes**: `vendor/bin/pint --dirty --format agent`. **After route/controller changes**: `php artisan wayfinder:generate --with-form`.
- **File/route/page names are lowercase kebab-case**; in-code identifiers stay PascalCase/camelCase.
- **No `useMemo`/`useCallback`** (React Compiler is on) — compute inline.
- **Frontend cannot be browser-verified here** — verify with `bun run types:check`, `bun run lint:check`, `bun run test`; the user eyeballs the running app.
- **Media disk** is `'public'`. Existing image upload cap is `max:8192` KiB.
- Wayfinder route helpers import from `@/actions/...` (controllers) or `@/routes/...` (named routes).

---

## File Structure

**Backend (new)**
- `database/migrations/2026_06_24_000001_add_screenshot_columns_to_post_media.php`
- `app/Http/Controllers/Posts/PostScreenshotController.php`
- `app/Http/Requests/Post/StorePostScreenshotRequest.php`
- `app/Http/Requests/Post/UpdatePostScreenshotRequest.php`

**Backend (modified)**
- `app/Models/PostMedia.php` — Fillable + casts + `source_url()` + `toView()`.
- `app/Support/PostView.php` — use `$media->toView()`.
- `app/Http/Controllers/Posts/PostMediaController.php` — use `$media->toView()`.
- `app/Http/Controllers/Posts/PostVideoUploadController.php` — use `$media->toView()`.
- `app/Services/Posts/MediaStorageService.php` — `storeBeautified()` + `replaceBeautified()`.
- `routes/posts.php` — two new routes.

**Frontend (new)**
- `resources/js/lib/screenshot/gradients.ts`
- `resources/js/lib/screenshot/settings.ts`
- `resources/js/lib/screenshot/layout.ts`
- `resources/js/lib/screenshot/crop.ts`
- `resources/js/lib/screenshot/export.ts`
- `resources/js/lib/screenshot/__tests__/gradients.test.ts`
- `resources/js/lib/screenshot/__tests__/settings.test.ts`
- `resources/js/lib/screenshot/__tests__/layout.test.ts`
- `resources/js/lib/screenshot/__tests__/export.test.ts`
- `resources/js/components/compose/screenshot-stage.tsx`
- `resources/js/components/compose/crop-overlay.tsx`
- `resources/js/components/compose/screenshot-editor.tsx`
- `resources/js/hooks/compose/use-screenshot.ts`

**Frontend (modified)**
- `resources/js/types/compose.ts` — extend `MediaView`.
- `resources/js/lib/compose/composer-state.ts` — `replaceMedia` reducer action.
- `resources/js/components/compose/composer-toolbar.tsx` — Beautify button + props.
- `resources/js/components/compose/media-chips.tsx` — Edit affordance.
- `resources/js/components/compose/composer.tsx` — wire the editor + hook.

---

## Task 1: Persist screenshot columns on PostMedia + DRY the media serializer

**Files:**
- Create: `database/migrations/2026_06_24_000001_add_screenshot_columns_to_post_media.php`
- Modify: `app/Models/PostMedia.php`
- Modify: `app/Support/PostView.php:56-64`
- Modify: `app/Http/Controllers/Posts/PostMediaController.php:28-35`
- Modify: `app/Http/Controllers/Posts/PostVideoUploadController.php:97-104`
- Test: `tests/Unit/PostMediaTest.php`

**Interfaces:**
- Produces: `PostMedia::toView(): array{id,url,mime,kind,duration_seconds,alt_text,position,edit_settings,source_url}`; `PostMedia::source_url(): ?string`; new nullable columns `source_disk`, `source_path`, `edit_settings` (cast `array`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/PostMediaTest.php` (create the file if missing, mirroring existing unit test style — no `declare(strict_types=1)` per project convention for new tests):

```php
<?php

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

test('toView exposes edit settings and source url for a beautified image', function () {
    Storage::fake('public');

    $media = PostMedia::factory()->create([
        'source_disk' => 'public',
        'source_path' => 'media/ws/source.png',
        'edit_settings' => ['version' => 1, 'padding' => 64],
    ]);

    $view = $media->toView();

    expect($view['edit_settings'])->toBe(['version' => 1, 'padding' => 64])
        ->and($view['source_url'])->toContain('media/ws/source.png')
        ->and($view['id'])->toBe($media->id)
        ->and($view)->toHaveKeys(['url', 'mime', 'kind', 'duration_seconds', 'alt_text', 'position']);
});

test('toView returns null edit settings and source url for a plain image', function () {
    $media = PostMedia::factory()->create();

    expect($media->toView()['edit_settings'])->toBeNull()
        ->and($media->toView()['source_url'])->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PostMediaTest`
Expected: FAIL — `Call to undefined method App\Models\PostMedia::toView()` (and the migration columns don't exist yet).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_24_000001_add_screenshot_columns_to_post_media.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->string('source_disk')->nullable()->after('path');
            $table->string('source_path')->nullable()->after('source_disk');
            $table->json('edit_settings')->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropColumn(['source_disk', 'source_path', 'edit_settings']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/PostMedia.php`: add the three columns to the `#[Fillable([...])]` array (after `'duration_seconds',`):

```php
    'source_disk',
    'source_path',
    'edit_settings',
```

Add the `@property` PHPDoc lines near the other properties:

```php
 * @property string|null $source_disk
 * @property string|null $source_path
 * @property array<string, mixed>|null $edit_settings
```

Add a casts method and the two new methods (place after `url()`):

```php
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['edit_settings' => 'array'];
    }

    public function source_url(): ?string
    {
        if ($this->source_path === null) {
            return null;
        }

        return Storage::disk($this->source_disk ?? $this->disk)->url($this->source_path);
    }

    /**
     * @return array{id: string, url: string, mime: string, kind: string, duration_seconds: int|null, alt_text: string|null, position: int, edit_settings: array<string, mixed>|null, source_url: string|null}
     */
    public function toView(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'mime' => $this->mime,
            'kind' => $this->kind,
            'duration_seconds' => $this->duration_seconds,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
            'edit_settings' => $this->edit_settings,
            'source_url' => $this->source_url(),
        ];
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=PostMediaTest`
Expected: PASS.

- [ ] **Step 6: DRY the three serialization sites onto `toView()`**

In `app/Support/PostView.php`, replace the `'media' => $post->media->map(...)->all(),` block (lines ~56-64) with:

```php
            'media' => $post->media->map(fn (PostMedia $media): array => $media->toView())->values()->all(),
```

In `app/Http/Controllers/Posts/PostMediaController.php`, replace the `return response()->json(['media' => [ ... ]], 201);` block in `store()` with:

```php
        return response()->json(['media' => $media->toView()], 201);
```

In `app/Http/Controllers/Posts/PostVideoUploadController.php`, replace the `return response()->json(['media' => [ ... ]], 201);` block in `store()` with:

```php
        return response()->json(['media' => $media->toView()], 201);
```

- [ ] **Step 7: Run the existing media/post suites to confirm the refactor is behavior-preserving**

Run: `php artisan test --compact --filter="MediaApiTest|PostViewTest|PostMediaTest"`
Expected: PASS (existing assertions on `media.mime`, `media.kind`, etc. still hold; new keys are additive).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/PostMedia.php app/Support/PostView.php app/Http/Controllers/Posts/PostMediaController.php app/Http/Controllers/Posts/PostVideoUploadController.php tests/Unit/PostMediaTest.php
git commit -m "feat(post-media): persist screenshot source + edit_settings, DRY media serialization via toView()"
```

---

## Task 2: MediaStorageService — store/replace beautified media

**Files:**
- Modify: `app/Services/Posts/MediaStorageService.php`
- Test: `tests/Feature/Posts/MediaStorageServiceTest.php`

**Interfaces:**
- Consumes: `PostMedia` (Task 1 columns).
- Produces:
  - `MediaStorageService::storeBeautified(string $workspaceId, UploadedFile $composed, UploadedFile $source, array $settings): PostMedia`
  - `MediaStorageService::replaceBeautified(PostMedia $media, UploadedFile $composed, array $settings): PostMedia`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Posts/MediaStorageServiceTest.php`:

```php
test('storeBeautified persists composed + source files and settings', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();

    $media = app(MediaStorageService::class)->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('composed.png', 800, 600),
        UploadedFile::fake()->image('source.png', 1200, 900),
        ['version' => 1, 'padding' => 64],
    );

    Storage::disk('public')->assertExists($media->path);
    Storage::disk('public')->assertExists($media->source_path);
    expect($media->edit_settings)->toBe(['version' => 1, 'padding' => 64])
        ->and($media->source_disk)->toBe('public')
        ->and($media->workspace_id)->toBe($workspace->id);
});

test('replaceBeautified swaps the composed file and settings but keeps the source', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $service = app(MediaStorageService::class);

    $media = $service->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('c1.png', 400, 400),
        UploadedFile::fake()->image('s.png', 800, 800),
        ['version' => 1, 'padding' => 10],
    );
    $oldPath = $media->path;
    $sourcePath = $media->source_path;

    $updated = $service->replaceBeautified(
        $media,
        UploadedFile::fake()->image('c2.png', 500, 500),
        ['version' => 1, 'padding' => 99],
    );

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($updated->path);
    Storage::disk('public')->assertExists($sourcePath);
    expect($updated->path)->not->toBe($oldPath)
        ->and($updated->source_path)->toBe($sourcePath)
        ->and($updated->edit_settings)->toBe(['version' => 1, 'padding' => 99]);
});
```

Ensure these imports exist at the top of the file (add any missing):

```php
use App\Models\Workspace;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MediaStorageServiceTest`
Expected: FAIL — `Call to undefined method ...::storeBeautified()`.

- [ ] **Step 3: Implement the two methods**

In `app/Services/Posts/MediaStorageService.php`, add after `storeFromUrl()`:

```php
    /**
     * Store a beautified screenshot: the composed image becomes the post's media,
     * the original source is retained for non-destructive re-editing.
     *
     * @param  array<string, mixed>  $settings
     */
    public function storeBeautified(string $workspaceId, UploadedFile $composed, UploadedFile $source, array $settings): PostMedia
    {
        $disk = 'public';
        $path = $composed->store('media/'.$workspaceId, $disk);
        $sourcePath = $source->store('media/'.$workspaceId, $disk);

        $dimensions = @getimagesize($composed->getRealPath()) ?: [null, null];

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'source_disk' => $disk,
            'source_path' => $sourcePath,
            'edit_settings' => $settings,
            'mime' => (string) $composed->getMimeType(),
            'size_bytes' => $composed->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => null,
            'position' => 0,
        ]);
    }

    /**
     * Replace the composed file + settings of an existing beautified media, keeping its source.
     *
     * @param  array<string, mixed>  $settings
     */
    public function replaceBeautified(PostMedia $media, UploadedFile $composed, array $settings): PostMedia
    {
        Storage::disk($media->disk)->delete($media->path);

        $path = $composed->store('media/'.$media->workspace_id, $media->disk);
        $dimensions = @getimagesize($composed->getRealPath()) ?: [null, null];

        $media->update([
            'path' => $path,
            'edit_settings' => $settings,
            'mime' => (string) $composed->getMimeType(),
            'size_bytes' => $composed->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
        ]);

        return $media->refresh();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=MediaStorageServiceTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Posts/MediaStorageService.php tests/Feature/Posts/MediaStorageServiceTest.php
git commit -m "feat(media-storage): storeBeautified + replaceBeautified"
```

---

## Task 3: PostScreenshotController + requests + routes

**Files:**
- Create: `app/Http/Requests/Post/StorePostScreenshotRequest.php`
- Create: `app/Http/Requests/Post/UpdatePostScreenshotRequest.php`
- Create: `app/Http/Controllers/Posts/PostScreenshotController.php`
- Modify: `routes/posts.php`
- Test: `tests/Feature/Posts/ScreenshotApiTest.php`

**Interfaces:**
- Consumes: `MediaStorageService::storeBeautified/replaceBeautified` (Task 2), `PostMedia::toView()` (Task 1).
- Produces: routes `posts.screenshot.store` (`POST /posts/{post}/screenshot`) and `posts.screenshot.update` (`PUT /posts/{post}/screenshot/{media}`), both returning `{ media: <toView> }`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Posts/ScreenshotApiTest.php` (reuse the `memberWithDraft()` helper pattern from `MediaApiTest.php`):

```php
<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function screenshotMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $post = test()->postJson('/posts', ['base_text' => '', 'destination' => ['kind' => 'all']])->json('post');

    return [$user, $workspace, $post];
}

function settingsPayload(): string
{
    return json_encode([
        'version' => 1,
        'background' => ['type' => 'gradient', 'id' => 'sunset', 'angle' => 135, 'stops' => [['color' => '#f00', 'at' => 0], ['color' => '#00f', 'at' => 1]]],
        'padding' => 64,
        'radius' => 12,
        'shadow' => 'medium',
        'aspect' => 'auto',
        'tilt' => ['rotateX' => 0, 'rotateY' => 0],
        'crop' => null,
    ]);
}

test('POST /posts/{post}/screenshot stores composed + source and returns edit settings', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = screenshotMember();

    $response = test()->post("/posts/{$post['id']}/screenshot", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('media.mime', 'image/png')
        ->assertJsonPath('media.edit_settings.padding', 64);
    expect($response->json('media.source_url'))->not->toBeNull();
});

test('PUT /posts/{post}/screenshot/{media} replaces composed + settings', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = screenshotMember();

    $mediaId = test()->post("/posts/{$post['id']}/screenshot", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->json('media.id');

    $newSettings = json_decode(settingsPayload(), true);
    $newSettings['padding'] = 120;

    test()->put("/posts/{$post['id']}/screenshot/{$mediaId}", [
        'composed' => UploadedFile::fake()->image('out2.png', 900, 600),
        'settings' => json_encode($newSettings),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('media.edit_settings.padding', 120);

    expect(PostMedia::findOrFail($mediaId)->source_path)->not->toBeNull();
});

test('it rejects a screenshot upload to a non-editable post', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = screenshotMember();
    Post::findOrFail($post['id'])->forceFill(['status' => 'published'])->save();

    test()->post("/posts/{$post['id']}/screenshot", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

test('it 404s when updating media from another workspace', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = screenshotMember();
    $foreign = PostMedia::factory()->create();

    test()->put("/posts/{$post['id']}/screenshot/{$foreign->id}", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->assertStatus(404);
});

test('it rejects a non-image composed file', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = screenshotMember();

    test()->post("/posts/{$post['id']}/screenshot", [
        'composed' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ScreenshotApiTest`
Expected: FAIL — 404 route not defined.

- [ ] **Step 3: Create the form requests**

`app/Http/Requests/Post/StorePostScreenshotRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class StorePostScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * Decode the JSON-encoded settings field (sent as a string in the multipart body).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->settings)) {
            $this->merge(['settings' => json_decode($this->settings, true)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'composed' => ['required', 'file', 'mimetypes:image/png', 'max:8192'],
            'source' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:8192'],
            'settings' => ['required', 'array'],
            'settings.version' => ['required', 'integer'],
            'settings.background' => ['required', 'array'],
            'settings.padding' => ['required', 'numeric'],
            'settings.radius' => ['required', 'numeric'],
            'settings.shadow' => ['required', 'string'],
            'settings.aspect' => ['required', 'string'],
            'settings.tilt' => ['required', 'array'],
            'settings.crop' => ['nullable', 'array'],
        ];
    }
}
```

`app/Http/Requests/Post/UpdatePostScreenshotRequest.php` — identical but without the `source` rule:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->settings)) {
            $this->merge(['settings' => json_decode($this->settings, true)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'composed' => ['required', 'file', 'mimetypes:image/png', 'max:8192'],
            'settings' => ['required', 'array'],
            'settings.version' => ['required', 'integer'],
            'settings.background' => ['required', 'array'],
            'settings.padding' => ['required', 'numeric'],
            'settings.radius' => ['required', 'numeric'],
            'settings.shadow' => ['required', 'string'],
            'settings.aspect' => ['required', 'string'],
            'settings.tilt' => ['required', 'array'],
            'settings.crop' => ['nullable', 'array'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/Posts/PostScreenshotController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostScreenshotRequest;
use App\Http\Requests\Post\UpdatePostScreenshotRequest;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;

class PostScreenshotController extends Controller
{
    public function __construct(private readonly MediaStorageService $media) {}

    public function store(StorePostScreenshotRequest $request, Post $post): JsonResponse
    {
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        $media = $this->media->storeBeautified(
            $post->workspace_id,
            $request->file('composed'),
            $request->file('source'),
            $request->validated('settings'),
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function update(UpdatePostScreenshotRequest $request, Post $post, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $post->workspace_id, 404);
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        $updated = $this->media->replaceBeautified(
            $media,
            $request->file('composed'),
            $request->validated('settings'),
        );

        return response()->json(['media' => $updated->toView()]);
    }
}
```

- [ ] **Step 5: Register routes**

In `routes/posts.php`: add the import next to the other `Posts\` controller imports:

```php
use App\Http\Controllers\Posts\PostScreenshotController;
```

Inside the existing `Route::middleware('throttle:60,1')->group(...)` block (next to the media routes), add:

```php
        Route::post('posts/{post}/screenshot', [PostScreenshotController::class, 'store'])->name('posts.screenshot.store');
        Route::put('posts/{post}/screenshot/{media}', [PostScreenshotController::class, 'update'])->name('posts.screenshot.update');
```

The existing `Route::bind('media', ...)` already workspace-scopes `{media}` (404s foreign ids before the controller's own guard).

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=ScreenshotApiTest`
Expected: PASS (all 6 cases).

- [ ] **Step 7: Regenerate Wayfinder + Pint + commit**

```bash
php artisan wayfinder:generate --with-form
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Posts/PostScreenshotController.php app/Http/Requests/Post/StorePostScreenshotRequest.php app/Http/Requests/Post/UpdatePostScreenshotRequest.php routes/posts.php resources/js/actions resources/js/routes
git commit -m "feat(screenshot): PostScreenshotController store/update + routes"
```

---

## Task 4: Gradient presets (`gradients.ts`)

**Files:**
- Create: `resources/js/lib/screenshot/gradients.ts`
- Test: `resources/js/lib/screenshot/__tests__/gradients.test.ts`

**Interfaces:**
- Produces:
  - `type GradientStop = { color: string; at: number }`
  - `type GradientFill = { type: 'gradient'; id: string; angle: number; stops: GradientStop[] }`
  - `type BackgroundFill = GradientFill` (union seam for future sources)
  - `type GradientPreset = { id: string; name: string; angle: number; stops: GradientStop[] }`
  - `const GRADIENTS: GradientPreset[]`
  - `gradientToFill(preset: GradientPreset): GradientFill`
  - `findGradient(id: string): GradientPreset | undefined`
  - `backgroundCss(fill: BackgroundFill): string`

- [ ] **Step 1: Write the failing test**

`resources/js/lib/screenshot/__tests__/gradients.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import {
    backgroundCss,
    findGradient,
    GRADIENTS,
    gradientToFill,
} from '../gradients';

describe('gradient presets', () => {
    it('ships at least 8 presets with unique ids', () => {
        const ids = GRADIENTS.map((g) => g.id);
        expect(GRADIENTS.length).toBeGreaterThanOrEqual(8);
        expect(new Set(ids).size).toBe(ids.length);
    });

    it('every preset has >= 2 stops and a valid angle', () => {
        for (const g of GRADIENTS) {
            expect(g.stops.length).toBeGreaterThanOrEqual(2);
            expect(g.angle).toBeGreaterThanOrEqual(0);
            expect(g.angle).toBeLessThanOrEqual(360);
            for (const s of g.stops) {
                expect(s.at).toBeGreaterThanOrEqual(0);
                expect(s.at).toBeLessThanOrEqual(1);
            }
        }
    });

    it('findGradient resolves a known id and rejects an unknown one', () => {
        expect(findGradient(GRADIENTS[0].id)?.id).toBe(GRADIENTS[0].id);
        expect(findGradient('nope')).toBeUndefined();
    });

    it('backgroundCss renders a linear-gradient with angle + stops', () => {
        const css = backgroundCss(gradientToFill(GRADIENTS[0]));
        expect(css).toContain('linear-gradient(');
        expect(css).toContain('deg');
        expect(css).toContain('%');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bun run test gradients`
Expected: FAIL — cannot resolve `../gradients`.

- [ ] **Step 3: Implement `gradients.ts`**

```ts
export type GradientStop = { color: string; at: number };

export type GradientFill = {
    type: 'gradient';
    id: string;
    angle: number;
    stops: GradientStop[];
};

/** Future background sources (solid, image, …) join this union. */
export type BackgroundFill = GradientFill;

export type GradientPreset = {
    id: string;
    name: string;
    angle: number;
    stops: GradientStop[];
};

function stops(...colors: string[]): GradientStop[] {
    const last = colors.length - 1;

    return colors.map((color, i) => ({ color, at: last === 0 ? 0 : i / last }));
}

export const GRADIENTS: GradientPreset[] = [
    { id: 'sunset', name: 'Sunset', angle: 135, stops: stops('#ff9a9e', '#fad0c4') },
    { id: 'oceanic', name: 'Oceanic', angle: 135, stops: stops('#2193b0', '#6dd5ed') },
    { id: 'grape', name: 'Grape', angle: 135, stops: stops('#a18cd1', '#fbc2eb') },
    { id: 'citrus', name: 'Citrus', angle: 135, stops: stops('#f6d365', '#fda085') },
    { id: 'mint', name: 'Mint', angle: 135, stops: stops('#43e97b', '#38f9d7') },
    { id: 'royal', name: 'Royal', angle: 135, stops: stops('#667eea', '#764ba2') },
    { id: 'ember', name: 'Ember', angle: 135, stops: stops('#f09819', '#edde5d') },
    { id: 'dusk', name: 'Dusk', angle: 135, stops: stops('#4b6cb7', '#182848') },
    { id: 'rose', name: 'Rose', angle: 135, stops: stops('#e55d87', '#5fc3e4') },
    { id: 'slate', name: 'Slate', angle: 135, stops: stops('#1f2937', '#4b5563') },
    { id: 'aurora', name: 'Aurora', angle: 135, stops: stops('#00c6ff', '#0072ff') },
    { id: 'peach', name: 'Peach', angle: 135, stops: stops('#ffecd2', '#fcb69f') },
];

export function gradientToFill(preset: GradientPreset): GradientFill {
    return {
        type: 'gradient',
        id: preset.id,
        angle: preset.angle,
        stops: preset.stops,
    };
}

export function findGradient(id: string): GradientPreset | undefined {
    return GRADIENTS.find((g) => g.id === id);
}

export function backgroundCss(fill: BackgroundFill): string {
    const stopList = fill.stops
        .map((s) => `${s.color} ${Math.round(s.at * 100)}%`)
        .join(', ');

    return `linear-gradient(${fill.angle}deg, ${stopList})`;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bun run test gradients`
Expected: PASS.

- [ ] **Step 5: Lint/format/types + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check
git add resources/js/lib/screenshot/gradients.ts resources/js/lib/screenshot/__tests__/gradients.test.ts
git commit -m "feat(screenshot): gradient presets + background css"
```

---

## Task 5: Edit settings model (`settings.ts`) + extend `MediaView`

**Files:**
- Create: `resources/js/lib/screenshot/settings.ts`
- Modify: `resources/js/types/compose.ts`
- Test: `resources/js/lib/screenshot/__tests__/settings.test.ts`

**Interfaces:**
- Consumes: `BackgroundFill`, `GRADIENTS`, `gradientToFill`, `findGradient` (Task 4).
- Produces:
  - `type ShadowPreset = 'none' | 'soft' | 'medium' | 'strong'`
  - `type AspectPreset = 'auto' | '1:1' | '4:3' | '3:4' | '16:9' | '9:16'`
  - `type CropRect = { x: number; y: number; width: number; height: number }`
  - `type EditSettings = { version: 1; background: BackgroundFill; padding: number; radius: number; shadow: ShadowPreset; aspect: AspectPreset; tilt: { rotateX: number; rotateY: number }; crop: CropRect | null }`
  - `const SHADOW_PRESETS`, `const ASPECT_PRESETS` (readonly arrays for UI iteration)
  - `defaultSettings(): EditSettings`
  - `normalizeSettings(raw: unknown): EditSettings`
- `MediaView` gains `edit_settings: EditSettings | null` and `source_url: string | null`.

- [ ] **Step 1: Write the failing test**

`resources/js/lib/screenshot/__tests__/settings.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import { defaultSettings, normalizeSettings } from '../settings';

describe('edit settings', () => {
    it('defaultSettings is a valid version-1 settings object', () => {
        const s = defaultSettings();
        expect(s.version).toBe(1);
        expect(s.background.type).toBe('gradient');
        expect(s.crop).toBeNull();
        expect(s.tilt).toEqual({ rotateX: 0, rotateY: 0 });
    });

    it('normalizeSettings fills a partial object with defaults', () => {
        const s = normalizeSettings({ padding: 100 });
        expect(s.padding).toBe(100);
        expect(s.aspect).toBe('auto');
        expect(s.shadow).toBe(defaultSettings().shadow);
    });

    it('normalizeSettings rejects garbage and falls back to defaults', () => {
        expect(normalizeSettings(null).padding).toBe(defaultSettings().padding);
        expect(normalizeSettings('boom').version).toBe(1);
        expect(normalizeSettings({ shadow: 'wat' }).shadow).toBe(defaultSettings().shadow);
        expect(normalizeSettings({ aspect: 'wat' }).aspect).toBe('auto');
    });

    it('normalizeSettings resolves a known gradient id and ignores unknown ids', () => {
        const known = normalizeSettings({ background: { type: 'gradient', id: 'royal' } });
        expect(known.background.id).toBe('royal');
        const unknown = normalizeSettings({ background: { type: 'gradient', id: 'nope' } });
        expect(unknown.background.id).toBe(defaultSettings().background.id);
    });

    it('normalizeSettings clamps a crop rect object and drops a non-object crop', () => {
        const withCrop = normalizeSettings({ crop: { x: 1, y: 2, width: 3, height: 4 } });
        expect(withCrop.crop).toEqual({ x: 1, y: 2, width: 3, height: 4 });
        expect(normalizeSettings({ crop: 'x' }).crop).toBeNull();
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bun run test settings`
Expected: FAIL — cannot resolve `../settings`.

- [ ] **Step 3: Implement `settings.ts`**

```ts
import type { BackgroundFill } from './gradients';
import { findGradient, gradientToFill, GRADIENTS } from './gradients';

export type ShadowPreset = 'none' | 'soft' | 'medium' | 'strong';
export type AspectPreset = 'auto' | '1:1' | '4:3' | '3:4' | '16:9' | '9:16';

export type CropRect = { x: number; y: number; width: number; height: number };

export type EditSettings = {
    version: 1;
    background: BackgroundFill;
    padding: number;
    radius: number;
    shadow: ShadowPreset;
    aspect: AspectPreset;
    tilt: { rotateX: number; rotateY: number };
    crop: CropRect | null;
};

export const SHADOW_PRESETS: readonly ShadowPreset[] = [
    'none',
    'soft',
    'medium',
    'strong',
];

export const ASPECT_PRESETS: readonly AspectPreset[] = [
    'auto',
    '1:1',
    '4:3',
    '3:4',
    '16:9',
    '9:16',
];

export function defaultSettings(): EditSettings {
    return {
        version: 1,
        background: gradientToFill(GRADIENTS[0]),
        padding: 64,
        radius: 12,
        shadow: 'medium',
        aspect: 'auto',
        tilt: { rotateX: 0, rotateY: 0 },
        crop: null,
    };
}

function asRecord(value: unknown): Record<string, unknown> {
    return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function numberOr(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function normalizeBackground(raw: unknown): BackgroundFill {
    const rec = asRecord(raw);
    const preset =
        (typeof rec.id === 'string' && findGradient(rec.id)) || GRADIENTS[0];

    return gradientToFill(preset);
}

function normalizeCrop(raw: unknown): CropRect | null {
    if (!raw || typeof raw !== 'object') {
        return null;
    }
    const rec = raw as Record<string, unknown>;
    if (
        ['x', 'y', 'width', 'height'].some((k) => typeof rec[k] !== 'number')
    ) {
        return null;
    }

    return {
        x: rec.x as number,
        y: rec.y as number,
        width: rec.width as number,
        height: rec.height as number,
    };
}

export function normalizeSettings(raw: unknown): EditSettings {
    const d = defaultSettings();
    const rec = asRecord(raw);
    const tilt = asRecord(rec.tilt);

    return {
        version: 1,
        background: normalizeBackground(rec.background),
        padding: numberOr(rec.padding, d.padding),
        radius: numberOr(rec.radius, d.radius),
        shadow: SHADOW_PRESETS.includes(rec.shadow as ShadowPreset)
            ? (rec.shadow as ShadowPreset)
            : d.shadow,
        aspect: ASPECT_PRESETS.includes(rec.aspect as AspectPreset)
            ? (rec.aspect as AspectPreset)
            : d.aspect,
        tilt: {
            rotateX: numberOr(tilt.rotateX, 0),
            rotateY: numberOr(tilt.rotateY, 0),
        },
        crop: normalizeCrop(rec.crop),
    };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bun run test settings`
Expected: PASS.

- [ ] **Step 5: Extend `MediaView`**

In `resources/js/types/compose.ts`, add the import at the top:

```ts
import type { EditSettings } from '@/lib/screenshot/settings';
```

Extend the `MediaView` type with two fields (after `position`):

```ts
    edit_settings: EditSettings | null;
    source_url: string | null;
```

- [ ] **Step 6: Types check + commit**

Run: `bun run types:check`
Expected: PASS (no consumers break — both fields are read-only additions; existing `MediaView` literals in tests/components that omit them will surface as errors. If `resources/js/components/compose/__tests__` or `lib/compose/__tests__` construct `MediaView` literals, add `edit_settings: null, source_url: null` to them in this step.)

```bash
bun run lint:check && bun run format:check && bun run test
git add resources/js/lib/screenshot/settings.ts resources/js/lib/screenshot/__tests__/settings.test.ts resources/js/types/compose.ts resources/js/lib/compose/__tests__ resources/js/components/compose/__tests__
git commit -m "feat(screenshot): EditSettings model + normalize; extend MediaView"
```

---

## Task 6: Layout geometry (`layout.ts`)

**Files:**
- Create: `resources/js/lib/screenshot/layout.ts`
- Test: `resources/js/lib/screenshot/__tests__/layout.test.ts`

**Interfaces:**
- Consumes: `AspectPreset`, `CropRect` (Task 5).
- Produces:
  - `type Size = { width: number; height: number }`
  - `aspectToRatio(aspect: AspectPreset): number | null`
  - `stageDimensions(contentW: number, contentH: number, padding: number, aspect: AspectPreset): Size`
  - `clampCropRect(rect: CropRect, boundsW: number, boundsH: number): CropRect`
  - `type Corner = 'nw' | 'ne' | 'se' | 'sw'`
  - `resizeCorner(rect: CropRect, corner: Corner, dx: number, dy: number, ratio: number | null, boundsW: number, boundsH: number): CropRect`
  - `moveCropRect(rect: CropRect, dx: number, dy: number, boundsW: number, boundsH: number): CropRect`

- [ ] **Step 1: Write the failing test**

`resources/js/lib/screenshot/__tests__/layout.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import {
    aspectToRatio,
    clampCropRect,
    moveCropRect,
    resizeCorner,
    stageDimensions,
} from '../layout';

describe('aspectToRatio', () => {
    it('maps presets to ratios, auto to null', () => {
        expect(aspectToRatio('auto')).toBeNull();
        expect(aspectToRatio('1:1')).toBe(1);
        expect(aspectToRatio('16:9')).toBeCloseTo(16 / 9);
        expect(aspectToRatio('9:16')).toBeCloseTo(9 / 16);
    });
});

describe('stageDimensions', () => {
    it('auto hugs the padded content', () => {
        expect(stageDimensions(800, 600, 50, 'auto')).toEqual({
            width: 900,
            height: 700,
        });
    });

    it('fixed ratio grows the binding axis and keeps the content inside', () => {
        const s = stageDimensions(800, 600, 50, '1:1');
        expect(s.width).toBe(s.height);
        expect(s.width).toBeGreaterThanOrEqual(900);
        expect(s.height).toBeGreaterThanOrEqual(700);
    });

    it('16:9 yields the target ratio', () => {
        const s = stageDimensions(400, 400, 20, '16:9');
        expect(s.width / s.height).toBeCloseTo(16 / 9);
    });
});

describe('clampCropRect', () => {
    it('keeps the rect inside bounds', () => {
        expect(clampCropRect({ x: -10, y: -5, width: 50, height: 50 }, 100, 100)).toEqual({
            x: 0,
            y: 0,
            width: 50,
            height: 50,
        });
        const r = clampCropRect({ x: 80, y: 80, width: 50, height: 50 }, 100, 100);
        expect(r.x + r.width).toBeLessThanOrEqual(100);
        expect(r.y + r.height).toBeLessThanOrEqual(100);
    });
});

describe('resizeCorner', () => {
    it('anchors the opposite corner when dragging se', () => {
        const r = resizeCorner({ x: 10, y: 10, width: 40, height: 40 }, 'se', 10, 20, null, 200, 200);
        expect(r.x).toBe(10);
        expect(r.y).toBe(10);
        expect(r.width).toBe(50);
        expect(r.height).toBe(60);
    });

    it('honors a locked ratio', () => {
        const r = resizeCorner({ x: 0, y: 0, width: 40, height: 40 }, 'se', 40, 0, 1, 500, 500);
        expect(r.width).toBeCloseTo(r.height);
    });

    it('never escapes bounds', () => {
        const r = resizeCorner({ x: 0, y: 0, width: 40, height: 40 }, 'se', 9999, 9999, null, 100, 100);
        expect(r.x + r.width).toBeLessThanOrEqual(100);
        expect(r.y + r.height).toBeLessThanOrEqual(100);
    });
});

describe('moveCropRect', () => {
    it('translates and clamps to bounds', () => {
        expect(moveCropRect({ x: 10, y: 10, width: 20, height: 20 }, 5, -5, 100, 100)).toEqual({
            x: 15,
            y: 5,
            width: 20,
            height: 20,
        });
        const r = moveCropRect({ x: 90, y: 90, width: 20, height: 20 }, 50, 50, 100, 100);
        expect(r.x).toBe(80);
        expect(r.y).toBe(80);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bun run test layout`
Expected: FAIL — cannot resolve `../layout`.

- [ ] **Step 3: Implement `layout.ts`**

```ts
import type { AspectPreset, CropRect } from './settings';

export type Size = { width: number; height: number };
export type Corner = 'nw' | 'ne' | 'se' | 'sw';

const RATIOS: Record<Exclude<AspectPreset, 'auto'>, number> = {
    '1:1': 1,
    '4:3': 4 / 3,
    '3:4': 3 / 4,
    '16:9': 16 / 9,
    '9:16': 9 / 16,
};

export function aspectToRatio(aspect: AspectPreset): number | null {
    return aspect === 'auto' ? null : RATIOS[aspect];
}

/**
 * The exported background rectangle. The content (cropped image) is padded on
 * all sides; for a fixed ratio the stage grows along whichever axis is binding
 * so the padded content always fits and the stage has exactly the target ratio.
 */
export function stageDimensions(
    contentW: number,
    contentH: number,
    padding: number,
    aspect: AspectPreset,
): Size {
    const innerW = contentW + padding * 2;
    const innerH = contentH + padding * 2;
    const ratio = aspectToRatio(aspect);
    if (ratio === null) {
        return { width: innerW, height: innerH };
    }
    if (innerW / innerH > ratio) {
        return { width: innerW, height: innerW / ratio };
    }

    return { width: innerH * ratio, height: innerH };
}

export function clampCropRect(
    rect: CropRect,
    boundsW: number,
    boundsH: number,
): CropRect {
    const width = Math.max(1, Math.min(rect.width, boundsW));
    const height = Math.max(1, Math.min(rect.height, boundsH));
    const x = Math.max(0, Math.min(rect.x, boundsW - width));
    const y = Math.max(0, Math.min(rect.y, boundsH - height));

    return { x, y, width, height };
}

const OPPOSITE: Record<Corner, Corner> = {
    nw: 'se',
    ne: 'sw',
    se: 'nw',
    sw: 'ne',
};

function cornerPoint(rect: CropRect, corner: Corner): { x: number; y: number } {
    const right = rect.x + rect.width;
    const bottom = rect.y + rect.height;
    switch (corner) {
        case 'nw':
            return { x: rect.x, y: rect.y };
        case 'ne':
            return { x: right, y: rect.y };
        case 'se':
            return { x: right, y: bottom };
        case 'sw':
            return { x: rect.x, y: bottom };
    }
}

export function resizeCorner(
    rect: CropRect,
    corner: Corner,
    dx: number,
    dy: number,
    ratio: number | null,
    boundsW: number,
    boundsH: number,
): CropRect {
    const anchor = cornerPoint(rect, OPPOSITE[corner]);
    const moving = cornerPoint(rect, corner);
    const mx = moving.x + dx;
    const my = moving.y + dy;

    let width = Math.max(1, Math.abs(mx - anchor.x));
    let height = Math.max(1, Math.abs(my - anchor.y));
    if (ratio !== null) {
        if (width / height > ratio) {
            height = width / ratio;
        } else {
            width = height * ratio;
        }
    }

    const signX = Math.sign(moving.x - anchor.x) || 1;
    const signY = Math.sign(moving.y - anchor.y) || 1;
    const x = signX > 0 ? anchor.x : anchor.x - width;
    const y = signY > 0 ? anchor.y : anchor.y - height;

    return clampCropRect({ x, y, width, height }, boundsW, boundsH);
}

export function moveCropRect(
    rect: CropRect,
    dx: number,
    dy: number,
    boundsW: number,
    boundsH: number,
): CropRect {
    return clampCropRect(
        { ...rect, x: rect.x + dx, y: rect.y + dy },
        boundsW,
        boundsH,
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bun run test layout`
Expected: PASS.

- [ ] **Step 5: Lint/format/types + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check
git add resources/js/lib/screenshot/layout.ts resources/js/lib/screenshot/__tests__/layout.test.ts
git commit -m "feat(screenshot): layout geometry (stage dims, crop clamp/resize/move)"
```

---

## Task 7: Canvas adapters — `crop.ts` + `export.ts` (+ pure export-scale test)

**Files:**
- Create: `resources/js/lib/screenshot/crop.ts`
- Create: `resources/js/lib/screenshot/export.ts`
- Test: `resources/js/lib/screenshot/__tests__/export.test.ts`
- Add dependency: `html-to-image`

**Interfaces:**
- Consumes: `CropRect` (Task 5).
- Produces:
  - `computeExportScale(longestEdgePx: number, maxEdge?: number, baseScale?: number): number` (pure)
  - `cropToBlob(source: CanvasImageSource & { width: number; height: number }, rect: CropRect): Promise<Blob>` (thin canvas)
  - `loadImage(src: string): Promise<HTMLImageElement>` (thin)
  - `rasterizeStage(node: HTMLElement, naturalLongestEdge: number): Promise<Blob>` (thin, uses `html-to-image`)

- [ ] **Step 1: Add the approved dependency**

```bash
bun add html-to-image
```

- [ ] **Step 2: Write the failing test (pure scale math only — canvas/DOM are not testable in node env)**

`resources/js/lib/screenshot/__tests__/export.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import { computeExportScale } from '../export';

describe('computeExportScale', () => {
    it('uses the base scale when the result stays under the cap', () => {
        expect(computeExportScale(500, 2048, 2)).toBe(2);
    });

    it('caps the longest edge for large images', () => {
        expect(computeExportScale(2000, 2048, 2)).toBeCloseTo(2048 / 2000);
    });

    it('never returns more than the base scale', () => {
        expect(computeExportScale(10, 2048, 2)).toBe(2);
    });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `bun run test export`
Expected: FAIL — cannot resolve `../export`.

- [ ] **Step 4: Implement `export.ts`**

```ts
import { toBlob } from 'html-to-image';

/**
 * Pick a rasterization pixel-ratio: render at `baseScale` for crispness, but
 * cap the longest output edge at `maxEdge` so file size stays within platform
 * media limits.
 */
export function computeExportScale(
    longestEdgePx: number,
    maxEdge = 2048,
    baseScale = 2,
): number {
    if (longestEdgePx <= 0) {
        return baseScale;
    }
    const capped = maxEdge / longestEdgePx;

    return Math.min(baseScale, capped < baseScale ? capped : baseScale);
}

/** Rasterize the stage DOM node to a PNG blob. */
export async function rasterizeStage(
    node: HTMLElement,
    naturalLongestEdge: number,
): Promise<Blob> {
    const blob = await toBlob(node, {
        pixelRatio: computeExportScale(naturalLongestEdge),
        cacheBust: true,
    });
    if (!blob) {
        throw new Error('Failed to rasterize the screenshot.');
    }

    return blob;
}
```

- [ ] **Step 5: Implement `crop.ts`**

```ts
import type { CropRect } from './settings';

/** Load an image element from an object-URL or same-origin URL. */
export function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Could not load the image.'));
        img.src = src;
    });
}

/** Crop a region of the source image to a PNG blob via an offscreen canvas. */
export function cropToBlob(
    source: CanvasImageSource,
    rect: CropRect,
): Promise<Blob> {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(rect.width));
    canvas.height = Math.max(1, Math.round(rect.height));
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return Promise.reject(new Error('Canvas 2D is unavailable.'));
    }
    ctx.drawImage(
        source,
        rect.x,
        rect.y,
        rect.width,
        rect.height,
        0,
        0,
        canvas.width,
        canvas.height,
    );

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) {
                resolve(blob);
            } else {
                reject(new Error('Could not crop the image.'));
            }
        }, 'image/png');
    });
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `bun run test export`
Expected: PASS.

- [ ] **Step 7: Lint/format/types + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check
git add package.json bun.lock resources/js/lib/screenshot/crop.ts resources/js/lib/screenshot/export.ts resources/js/lib/screenshot/__tests__/export.test.ts
git commit -m "feat(screenshot): crop + html-to-image export adapters"
```

---

## Task 8: `screenshot-stage.tsx` — the styled DOM composition

**Files:**
- Create: `resources/js/components/compose/screenshot-stage.tsx`

**Interfaces:**
- Consumes: `EditSettings` (Task 5), `backgroundCss` (Task 4).
- Produces: `ScreenshotStage` — `forwardRef<HTMLDivElement, { imageSrc: string; settings: EditSettings; contentSize: { width: number; height: number } }>`. The forwarded ref points at the exported background node. Also exports `SHADOW_CSS: Record<ShadowPreset, string>`.

> No unit test (Vitest is node-env, no DOM). Verify with `tsc` and the running app.

- [ ] **Step 1: Implement the stage**

```tsx
import { forwardRef } from 'react';

import { backgroundCss } from '@/lib/screenshot/gradients';
import type { EditSettings, ShadowPreset } from '@/lib/screenshot/settings';

export const SHADOW_CSS: Record<ShadowPreset, string> = {
    none: 'none',
    soft: '0 8px 24px rgba(0,0,0,0.18)',
    medium: '0 18px 48px rgba(0,0,0,0.28)',
    strong: '0 32px 80px rgba(0,0,0,0.40)',
};

type Props = {
    imageSrc: string;
    settings: EditSettings;
    /** The cropped image's intrinsic pixel size (drives the stage element size). */
    contentSize: { width: number; height: number };
};

/**
 * The exported composition: a gradient background that pads a 3D-tilted,
 * rounded, shadowed screenshot. Rendered at natural pixel size; callers scale
 * it down for the preview via a CSS transform. The forwarded ref is the node
 * html-to-image rasterizes.
 */
export const ScreenshotStage = forwardRef<HTMLDivElement, Props>(
    function ScreenshotStage({ imageSrc, settings, contentSize }, ref) {
        const { padding, radius, shadow, tilt, background } = settings;

        return (
            <div
                ref={ref}
                style={{
                    boxSizing: 'border-box',
                    padding,
                    width: 'max-content',
                    background: backgroundCss(background),
                    display: 'grid',
                    placeItems: 'center',
                    overflow: 'hidden',
                    perspective: '1200px',
                }}
            >
                <img
                    src={imageSrc}
                    alt=""
                    width={contentSize.width}
                    height={contentSize.height}
                    draggable={false}
                    style={{
                        display: 'block',
                        width: contentSize.width,
                        height: contentSize.height,
                        borderRadius: radius,
                        boxShadow: SHADOW_CSS[shadow],
                        transform: `rotateX(${tilt.rotateX}deg) rotateY(${tilt.rotateY}deg)`,
                        transformStyle: 'preserve-3d',
                    }}
                />
            </div>
        );
    },
);
```

> Note: the fixed-aspect background sizing (Task 6 `stageDimensions`) is applied by the editor (Task 11) via an explicit `width`/`height` override when `aspect !== 'auto'`; in `auto` mode the `padding` + `max-content` width hugs the image as shown here.

- [ ] **Step 2: Types check + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check
git add resources/js/components/compose/screenshot-stage.tsx
git commit -m "feat(screenshot): styled DOM stage component"
```

---

## Task 9: `crop-overlay.tsx` — corner-drag crop UI

**Files:**
- Create: `resources/js/components/compose/crop-overlay.tsx`

**Interfaces:**
- Consumes: `CropRect` (Task 5), `resizeCorner`, `moveCropRect`, `aspectToRatio`, `Corner` (Task 6).
- Produces: `CropOverlay` — props `{ imageSrc: string; sourceSize: { width: number; height: number }; rect: CropRect; ratio: number | null; onChange: (rect: CropRect) => void }`. Renders the source image with a draggable crop rectangle (4 corner handles + body move) scaled to fit a fixed display box. All geometry delegates to `layout.ts` in source-pixel space.

> v1 uses 4 corner handles + body drag (freeform via corners; ratio-lock supported). Edge-only handles are deferred. No unit test (DOM); logic is covered by `layout.test.ts`.

- [ ] **Step 1: Implement the overlay**

```tsx
import { useRef, useState } from 'react';

import type { Corner } from '@/lib/screenshot/layout';
import { moveCropRect, resizeCorner } from '@/lib/screenshot/layout';
import type { CropRect } from '@/lib/screenshot/settings';
import { cn } from '@/lib/utils';

type Props = {
    imageSrc: string;
    sourceSize: { width: number; height: number };
    rect: CropRect;
    ratio: number | null;
    onChange: (rect: CropRect) => void;
};

const CORNERS: { corner: Corner; className: string }[] = [
    { corner: 'nw', className: 'left-0 top-0 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize' },
    { corner: 'ne', className: 'right-0 top-0 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize' },
    { corner: 'se', className: 'right-0 bottom-0 translate-x-1/2 translate-y-1/2 cursor-nwse-resize' },
    { corner: 'sw', className: 'left-0 bottom-0 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize' },
];

const DISPLAY_MAX = 420;

export function CropOverlay({
    imageSrc,
    sourceSize,
    rect,
    ratio,
    onChange,
}: Props) {
    const boxRef = useRef<HTMLDivElement | null>(null);
    const [drag, setDrag] = useState<
        { kind: 'move' | Corner; startX: number; startY: number; startRect: CropRect } | null
    >(null);

    // Scale source pixels → on-screen display pixels.
    const scale = Math.min(
        DISPLAY_MAX / sourceSize.width,
        DISPLAY_MAX / sourceSize.height,
    );
    const dispW = sourceSize.width * scale;
    const dispH = sourceSize.height * scale;

    function onPointerDown(
        e: React.PointerEvent,
        kind: 'move' | Corner,
    ) {
        e.preventDefault();
        e.stopPropagation();
        (e.target as Element).setPointerCapture?.(e.pointerId);
        setDrag({ kind, startX: e.clientX, startY: e.clientY, startRect: rect });
    }

    function onPointerMove(e: React.PointerEvent) {
        if (!drag) {
            return;
        }
        // Convert display-space delta back to source pixels.
        const dx = (e.clientX - drag.startX) / scale;
        const dy = (e.clientY - drag.startY) / scale;
        const next =
            drag.kind === 'move'
                ? moveCropRect(drag.startRect, dx, dy, sourceSize.width, sourceSize.height)
                : resizeCorner(drag.startRect, drag.kind, dx, dy, ratio, sourceSize.width, sourceSize.height);
        onChange(next);
    }

    function endDrag() {
        setDrag(null);
    }

    return (
        <div
            ref={boxRef}
            className="relative mx-auto touch-none select-none"
            style={{ width: dispW, height: dispH }}
            onPointerMove={onPointerMove}
            onPointerUp={endDrag}
            onPointerLeave={endDrag}
        >
            <img
                src={imageSrc}
                alt=""
                draggable={false}
                className="size-full"
            />
            <div className="pointer-events-none absolute inset-0 bg-black/40" />
            <div
                className="absolute cursor-move border-2 border-white shadow-[0_0_0_9999px_rgba(0,0,0,0.4)]"
                style={{
                    left: rect.x * scale,
                    top: rect.y * scale,
                    width: rect.width * scale,
                    height: rect.height * scale,
                }}
                onPointerDown={(e) => onPointerDown(e, 'move')}
            >
                <img
                    src={imageSrc}
                    alt=""
                    draggable={false}
                    className="pointer-events-none absolute max-w-none"
                    style={{
                        width: dispW,
                        height: dispH,
                        left: -rect.x * scale,
                        top: -rect.y * scale,
                    }}
                />
                {CORNERS.map(({ corner, className }) => (
                    <button
                        key={corner}
                        type="button"
                        aria-label={`Resize ${corner}`}
                        onPointerDown={(e) => onPointerDown(e, corner)}
                        className={cn(
                            'absolute size-3 rounded-full border border-border bg-white',
                            className,
                        )}
                    />
                ))}
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Types check + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check
git add resources/js/components/compose/crop-overlay.tsx
git commit -m "feat(screenshot): crop overlay (corner drag + move)"
```

---

## Task 10: `use-screenshot.ts` hook + `replaceMedia` reducer action

**Files:**
- Create: `resources/js/hooks/compose/use-screenshot.ts`
- Modify: `resources/js/lib/compose/composer-state.ts`

**Interfaces:**
- Consumes: `EditSettings` (Task 5), `MediaView` (Task 5), `PostScreenshotController` (Wayfinder, Task 3), `onEnsurePost`, `onAddMedia`, `onReplaceMedia` callbacks.
- Produces:
  - reducer action `{ type: 'replaceMedia'; media: MediaView }`
  - `useScreenshot({ onEnsurePost, onAddMedia, onReplaceMedia }): { applyNew(composed: Blob, source: Blob, settings: EditSettings): Promise<void>; applyEdit(mediaId: string, composed: Blob, settings: EditSettings): Promise<void>; isSaving: boolean }`

- [ ] **Step 1: Add the `replaceMedia` reducer action (with a failing test)**

In `resources/js/lib/compose/__tests__/composer-state.test.ts`, add:

```ts
it('replaceMedia swaps a media entry in place by id', () => {
    const base = hydrated();
    const existing = base.media[0];
    const next = composerReducer(base, {
        type: 'replaceMedia',
        media: { ...existing, url: 'new-url' },
    });
    expect(next.media.find((m) => m.id === existing.id)?.url).toBe('new-url');
    expect(next.media.length).toBe(base.media.length);
});
```

(If `hydrated()` produces no media, first push one through `addMedia` in the test; mirror the existing test setup.)

- [ ] **Step 2: Run it to verify it fails**

Run: `bun run test composer-state`
Expected: FAIL — reducer has no `replaceMedia` case.

- [ ] **Step 3: Implement the reducer action**

In `composer-state.ts`, add to the action union (near `addMedia`):

```ts
    | { type: 'replaceMedia'; media: MediaView }
```

Add the case (after `addMedia`):

```ts
        case 'replaceMedia':
            return {
                ...state,
                media: state.media.map((m) =>
                    m.id === action.media.id ? action.media : m,
                ),
                saveState: 'dirty',
            };
```

- [ ] **Step 4: Run it to verify it passes**

Run: `bun run test composer-state`
Expected: PASS.

- [ ] **Step 5: Implement the hook**

`resources/js/hooks/compose/use-screenshot.ts`:

```ts
import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostScreenshotController from '@/actions/App/Http/Controllers/Posts/PostScreenshotController';
import type { EditSettings } from '@/lib/screenshot/settings';
import type { MediaView } from '@/types/compose';

type Options = {
    onEnsurePost: () => Promise<string>;
    onAddMedia: (media: MediaView) => void;
    onReplaceMedia: (media: MediaView) => void;
};

function blobToFile(blob: Blob, name: string): File {
    return new File([blob], name, { type: blob.type || 'image/png' });
}

export function useScreenshot({
    onEnsurePost,
    onAddMedia,
    onReplaceMedia,
}: Options) {
    const http = useHttp<Record<string, unknown>, { media: MediaView }>({});
    const [isSaving, setIsSaving] = useState(false);

    async function applyNew(
        composed: Blob,
        source: Blob,
        settings: EditSettings,
    ): Promise<void> {
        setIsSaving(true);
        try {
            const id = await onEnsurePost();
            if (!id) {
                return;
            }
            http.transform(() => ({
                composed: blobToFile(composed, 'screenshot.png'),
                source: blobToFile(source, 'source.png'),
                settings: JSON.stringify(settings),
            }));
            const { media } = await http.post(
                PostScreenshotController.store(id).url,
                { onNetworkError: () => undefined },
            );
            onAddMedia(media);
        } catch {
            toast.error('Could not save the screenshot.');
        } finally {
            setIsSaving(false);
        }
    }

    async function applyEdit(
        mediaId: string,
        composed: Blob,
        settings: EditSettings,
    ): Promise<void> {
        setIsSaving(true);
        try {
            const id = await onEnsurePost();
            if (!id) {
                return;
            }
            http.transform(() => ({
                composed: blobToFile(composed, 'screenshot.png'),
                settings: JSON.stringify(settings),
                _method: 'put',
            }));
            const { media } = await http.post(
                PostScreenshotController.update({ post: id, media: mediaId }).url,
                { onNetworkError: () => undefined },
            );
            onReplaceMedia(media);
        } catch {
            toast.error('Could not update the screenshot.');
        } finally {
            setIsSaving(false);
        }
    }

    return { applyNew, applyEdit, isSaving };
}
```

> Note: confirm the generated `PostScreenshotController.update(...)` argument shape during implementation (`bun run types:check` will flag it). Wayfinder typically accepts `{ post, media }` for a two-parameter route; adjust the call to match the generated signature. The `_method: 'put'` field spoofs the PUT verb through a multipart POST (Laravel form-method spoofing), since file uploads must be POST.

- [ ] **Step 6: Types check + tests + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check && bun run test composer-state
git add resources/js/hooks/compose/use-screenshot.ts resources/js/lib/compose/composer-state.ts resources/js/lib/compose/__tests__/composer-state.test.ts
git commit -m "feat(screenshot): use-screenshot upload hook + replaceMedia reducer action"
```

---

## Task 11: `screenshot-editor.tsx` — the editor modal

**Files:**
- Create: `resources/js/components/compose/screenshot-editor.tsx`

**Interfaces:**
- Consumes: everything above — `EditSettings`/`defaultSettings`/`normalizeSettings`/`SHADOW_PRESETS`/`ASPECT_PRESETS` (Task 5), `GRADIENTS`/`gradientToFill` (Task 4), `stageDimensions`/`aspectToRatio`/`clampCropRect` (Task 6), `loadImage`/`cropToBlob` (Task 7), `rasterizeStage` (Task 7), `ScreenshotStage` (Task 8), `CropOverlay` (Task 9), `useScreenshot` (Task 10).
- Produces: `ScreenshotEditor` — props:
  ```ts
  {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Fresh beautify: a picked File. */
    sourceFile?: File | null;
    /** Re-edit: an existing media's source URL + persisted settings. */
    editTarget?: { mediaId: string; sourceUrl: string; settings: EditSettings } | null;
    screenshot: ReturnType<typeof import('@/hooks/compose/use-screenshot').useScreenshot>;
  }
  ```

> No unit test (DOM/canvas). Verify with `tsc`, lint, and the running app.

- [ ] **Step 1: Implement the editor**

Use the existing `Dialog` primitives (`@/components/ui/dialog`), `Slider` (`@/components/ui/slider`), and `Button`. (Confirm these exist under `resources/js/components/ui/`; the project uses shadcn-style primitives. If `Slider` is absent, add it via the project's shadcn workflow or fall back to `<input type="range">`.)

```tsx
import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { useScreenshot } from '@/hooks/compose/use-screenshot';
import { GRADIENTS, gradientToFill } from '@/lib/screenshot/gradients';
import { cropToBlob, loadImage } from '@/lib/screenshot/crop';
import { rasterizeStage } from '@/lib/screenshot/export';
import {
    aspectToRatio,
    clampCropRect,
    stageDimensions,
} from '@/lib/screenshot/layout';
import {
    ASPECT_PRESETS,
    defaultSettings,
    type EditSettings,
    SHADOW_PRESETS,
} from '@/lib/screenshot/settings';
import { cn } from '@/lib/utils';

import { CropOverlay } from './crop-overlay';
import { ScreenshotStage } from './screenshot-stage';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sourceFile?: File | null;
    editTarget?: {
        mediaId: string;
        sourceUrl: string;
        settings: EditSettings;
    } | null;
    screenshot: ReturnType<typeof useScreenshot>;
};

const PREVIEW_MAX = 460;

export function ScreenshotEditor({
    open,
    onOpenChange,
    sourceFile,
    editTarget,
    screenshot,
}: Props) {
    const stageRef = useRef<HTMLDivElement | null>(null);
    const [settings, setSettings] = useState<EditSettings>(defaultSettings);
    const [sourceUrl, setSourceUrl] = useState<string | null>(null);
    const [sourceImg, setSourceImg] = useState<HTMLImageElement | null>(null);
    // The cropped image as an object-URL fed to the stage; null until prepared.
    const [croppedUrl, setCroppedUrl] = useState<string | null>(null);
    const [cropMode, setCropMode] = useState(false);

    // Load the source (fresh file or re-edit URL) whenever the editor opens.
    useEffect(() => {
        if (!open) {
            return;
        }
        const url = sourceFile
            ? URL.createObjectURL(sourceFile)
            : (editTarget?.sourceUrl ?? null);
        setSourceUrl(url);
        setSettings(editTarget?.settings ?? defaultSettings());
        if (!url) {
            return;
        }
        let revoked = false;
        loadImage(url).then((img) => {
            if (!revoked) {
                setSourceImg(img);
            }
        });

        return () => {
            revoked = true;
            if (sourceFile && url) {
                URL.revokeObjectURL(url);
            }
        };
    }, [open, sourceFile, editTarget]);

    // Recompute the cropped image whenever the source or crop rect changes.
    useEffect(() => {
        if (!sourceImg) {
            return;
        }
        const rect = settings.crop ?? {
            x: 0,
            y: 0,
            width: sourceImg.naturalWidth,
            height: sourceImg.naturalHeight,
        };
        let revoked = false;
        cropToBlob(sourceImg, rect).then((blob) => {
            if (revoked) {
                return;
            }
            const url = URL.createObjectURL(blob);
            setCroppedUrl((prev) => {
                if (prev) {
                    URL.revokeObjectURL(prev);
                }

                return url;
            });
        });

        return () => {
            revoked = true;
        };
    }, [sourceImg, settings.crop]);

    if (!sourceImg || !sourceUrl) {
        // Still loading; render the shell so the dialog can animate in.
    }

    const contentW = settings.crop?.width ?? sourceImg?.naturalWidth ?? 1;
    const contentH = settings.crop?.height ?? sourceImg?.naturalHeight ?? 1;
    const stage = stageDimensions(contentW, contentH, settings.padding, settings.aspect);
    const previewScale = Math.min(1, PREVIEW_MAX / Math.max(stage.width, stage.height));

    async function apply() {
        const node = stageRef.current;
        if (!node || !croppedUrl) {
            return;
        }
        try {
            const composed = await rasterizeStage(node, Math.max(stage.width, stage.height));
            const sourceBlob = await fetch(sourceUrl!).then((r) => r.blob());
            if (editTarget) {
                await screenshot.applyEdit(editTarget.mediaId, composed, settings);
            } else {
                await screenshot.applyNew(composed, sourceBlob, settings);
            }
            onOpenChange(false);
        } catch {
            // toast handled in the hook / rasterizeStage throw surfaces below
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Beautify screenshot</DialogTitle>
                </DialogHeader>

                <div className="grid gap-6 md:grid-cols-[1fr_220px]">
                    {/* Preview / crop */}
                    <div className="grid min-h-[300px] place-items-center overflow-hidden rounded-lg bg-muted/40 p-4">
                        {cropMode && sourceImg ? (
                            <CropOverlay
                                imageSrc={sourceUrl}
                                sourceSize={{
                                    width: sourceImg.naturalWidth,
                                    height: sourceImg.naturalHeight,
                                }}
                                rect={
                                    settings.crop ?? {
                                        x: 0,
                                        y: 0,
                                        width: sourceImg.naturalWidth,
                                        height: sourceImg.naturalHeight,
                                    }
                                }
                                ratio={aspectToRatio(settings.aspect)}
                                onChange={(crop) =>
                                    setSettings((s) => ({
                                        ...s,
                                        crop: clampCropRect(
                                            crop,
                                            sourceImg.naturalWidth,
                                            sourceImg.naturalHeight,
                                        ),
                                    }))
                                }
                            />
                        ) : croppedUrl ? (
                            <div
                                style={{
                                    transform: `scale(${previewScale})`,
                                    transformOrigin: 'center',
                                }}
                            >
                                <ScreenshotStage
                                    ref={stageRef}
                                    imageSrc={croppedUrl}
                                    settings={settings}
                                    contentSize={{ width: contentW, height: contentH }}
                                />
                            </div>
                        ) : (
                            <div className="size-8 animate-spin rounded-full border-2 border-foreground/60 border-t-transparent" />
                        )}
                    </div>

                    {/* Controls */}
                    <div className="space-y-4 text-sm">
                        <Control label="Background">
                            <div className="grid grid-cols-4 gap-1.5">
                                {GRADIENTS.map((g) => (
                                    <button
                                        key={g.id}
                                        type="button"
                                        aria-label={g.name}
                                        onClick={() =>
                                            setSettings((s) => ({
                                                ...s,
                                                background: gradientToFill(g),
                                            }))
                                        }
                                        className={cn(
                                            'h-7 rounded-md ring-offset-2 ring-offset-background',
                                            settings.background.id === g.id &&
                                                'ring-2 ring-foreground',
                                        )}
                                        style={{
                                            background: `linear-gradient(${g.angle}deg, ${g.stops[0].color}, ${g.stops[g.stops.length - 1].color})`,
                                        }}
                                    />
                                ))}
                            </div>
                        </Control>

                        <RangeControl
                            label="Padding"
                            min={0}
                            max={200}
                            value={settings.padding}
                            onChange={(padding) => setSettings((s) => ({ ...s, padding }))}
                        />
                        <RangeControl
                            label="Corner radius"
                            min={0}
                            max={64}
                            value={settings.radius}
                            onChange={(radius) => setSettings((s) => ({ ...s, radius }))}
                        />
                        <RangeControl
                            label="Tilt X"
                            min={-30}
                            max={30}
                            value={settings.tilt.rotateX}
                            onChange={(rotateX) =>
                                setSettings((s) => ({ ...s, tilt: { ...s.tilt, rotateX } }))
                            }
                        />
                        <RangeControl
                            label="Tilt Y"
                            min={-30}
                            max={30}
                            value={settings.tilt.rotateY}
                            onChange={(rotateY) =>
                                setSettings((s) => ({ ...s, tilt: { ...s.tilt, rotateY } }))
                            }
                        />

                        <Control label="Shadow">
                            <div className="flex gap-1">
                                {SHADOW_PRESETS.map((sh) => (
                                    <button
                                        key={sh}
                                        type="button"
                                        onClick={() => setSettings((s) => ({ ...s, shadow: sh }))}
                                        className={cn(
                                            'flex-1 rounded-md border border-border py-1 text-xs capitalize',
                                            settings.shadow === sh && 'bg-foreground text-background',
                                        )}
                                    >
                                        {sh}
                                    </button>
                                ))}
                            </div>
                        </Control>

                        <Control label="Aspect">
                            <div className="grid grid-cols-3 gap-1">
                                {ASPECT_PRESETS.map((a) => (
                                    <button
                                        key={a}
                                        type="button"
                                        onClick={() => setSettings((s) => ({ ...s, aspect: a }))}
                                        className={cn(
                                            'rounded-md border border-border py-1 text-xs',
                                            settings.aspect === a && 'bg-foreground text-background',
                                        )}
                                    >
                                        {a}
                                    </button>
                                ))}
                            </div>
                        </Control>

                        <button
                            type="button"
                            onClick={() => setCropMode((v) => !v)}
                            className={cn(
                                'w-full rounded-md border border-border py-1.5 text-xs',
                                cropMode && 'bg-foreground text-background',
                            )}
                        >
                            {cropMode ? 'Done cropping' : 'Crop'}
                        </button>
                    </div>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        className="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={screenshot.isSaving || !croppedUrl}
                        onClick={apply}
                        className="rounded-md bg-foreground px-3 py-1.5 text-sm font-medium text-background disabled:opacity-50"
                    >
                        {screenshot.isSaving ? 'Saving…' : 'Apply'}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Control({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <div className="text-xs font-medium text-muted-foreground">{label}</div>
            {children}
        </div>
    );
}

function RangeControl({
    label,
    min,
    max,
    value,
    onChange,
}: {
    label: string;
    min: number;
    max: number;
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <Control label={`${label} (${Math.round(value)})`}>
            <input
                type="range"
                min={min}
                max={max}
                value={value}
                onChange={(e) => onChange(Number(e.target.value))}
                className="w-full accent-foreground"
            />
        </Control>
    );
}
```

> Implementation notes for the engineer:
> - Confirm `@/components/ui/dialog` exports `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogFooter`. If names differ, match the project's primitives.
> - For fixed aspect ratios, the stage element width hugs the image (`max-content`). To show the background ratio in the preview, wrap the stage in a centering box sized to `stage.width × stage.height × previewScale` and let the stage center within it; acceptable v1 behavior is the gradient hugging the image with extra ratio padding applied at export via an explicit width/height on the stage node. Settle this visually with the user.

- [ ] **Step 2: Types/lint/format + commit**

```bash
bun run lint:check && bun run format:check && bun run types:check
git add resources/js/components/compose/screenshot-editor.tsx
git commit -m "feat(screenshot): editor modal (controls, preview, crop, apply)"
```

---

## Task 12: Wire the editor into the composer

**Files:**
- Modify: `resources/js/components/compose/composer-toolbar.tsx`
- Modify: `resources/js/components/compose/media-chips.tsx`
- Modify: `resources/js/components/compose/composer.tsx`

**Interfaces:**
- Consumes: `ScreenshotEditor` (Task 11), `useScreenshot` (Task 10), `normalizeSettings` (Task 5).
- Produces: a "Beautify" button in the toolbar and an "Edit" affordance on beautified image chips, both opening `ScreenshotEditor`.

- [ ] **Step 1: Add a Beautify button to the toolbar**

In `composer-toolbar.tsx`:
- Add to `Props`: `onBeautify?: () => void;` and `onEditMedia?: (mediaId: string) => void;` (the latter passed through to `MediaChips`).
- Import `Sparkles` from `lucide-react` (add to the existing import).
- After the existing `EToolButton` for "Media" (inside the `!readOnly` fragment), add:

```tsx
                    {onBeautify && (
                        <EToolButton title="Beautify a screenshot" onClick={onBeautify}>
                            <Sparkles className="size-3.5" aria-hidden="true" />
                            <span>Beautify</span>
                        </EToolButton>
                    )}
```

- Pass `onEditMedia` into the `<MediaChips ... />` render as `onEdit={onEditMedia}`.

- [ ] **Step 2: Add an Edit affordance to beautified chips**

In `media-chips.tsx`:
- Add to `Props`: `onEdit?: (mediaId: string) => void;`.
- Import `Pencil` from `lucide-react`.
- In the interactive media map (not the read-only branch), when `m.edit_settings` is present and `onEdit` is defined, render a second `CornerButton`-style control positioned bottom-right. Reuse the existing hover-reveal pattern. Add inside the chip `<div className="group/chip relative">`, after the existing remove `CornerButton`:

```tsx
                                {onEdit && m.edit_settings && (
                                    <button
                                        type="button"
                                        aria-label="Edit screenshot"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onEdit(m.id);
                                        }}
                                        className={cn(
                                            'absolute -bottom-1.5 -right-1.5 z-10 grid size-4 place-items-center rounded-full',
                                            'border border-background bg-foreground text-background shadow-sm',
                                            'opacity-0 transition-opacity group-focus-within/chip:opacity-100 group-hover/chip:opacity-100',
                                        )}
                                    >
                                        <Pencil className="size-2.5" aria-hidden="true" />
                                    </button>
                                )}
```

- [ ] **Step 3: Wire state + the editor into `composer.tsx`**

In `composer.tsx`:
- Import: `import { ScreenshotEditor } from './screenshot-editor';`, `import { useScreenshot } from '@/hooks/compose/use-screenshot';`, `import { normalizeSettings } from '@/lib/screenshot/settings';`, and `import { useRef, useState } from 'react';` (extend existing React imports).
- Instantiate the hook next to `useMediaUploads`:

```tsx
    const screenshot = useScreenshot({
        onEnsurePost: ensurePost,
        onAddMedia: (m) => dispatch({ type: 'addMedia', media: m }),
        onReplaceMedia: (m) => dispatch({ type: 'replaceMedia', media: m }),
    });
```

- Add local editor state + a hidden file input:

```tsx
    const beautifyInput = useRef<HTMLInputElement | null>(null);
    const [editorOpen, setEditorOpen] = useState(false);
    const [beautifyFile, setBeautifyFile] = useState<File | null>(null);
    const [editTarget, setEditTarget] = useState<
        { mediaId: string; sourceUrl: string; settings: import('@/lib/screenshot/settings').EditSettings } | null
    >(null);

    function openBeautifyPicker() {
        setEditTarget(null);
        setBeautifyFile(null);
        beautifyInput.current?.click();
    }

    function openEditMedia(mediaId: string) {
        const m = state.media.find((x) => x.id === mediaId);
        if (!m || !m.source_url || !m.edit_settings) {
            return;
        }
        setBeautifyFile(null);
        setEditTarget({
            mediaId,
            sourceUrl: m.source_url,
            settings: normalizeSettings(m.edit_settings),
        });
        setEditorOpen(true);
    }
```

- Pass `onBeautify={openBeautifyPicker}` and `onEditMedia={openEditMedia}` to `<ComposerToolbar ... />`.
- Render the hidden input + the editor near the toolbar (inside the composer JSX, in the `!readOnly` area):

```tsx
                <input
                    ref={beautifyInput}
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/gif"
                    hidden
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) {
                            setBeautifyFile(file);
                            setEditTarget(null);
                            setEditorOpen(true);
                        }
                        e.target.value = '';
                    }}
                />
                <ScreenshotEditor
                    open={editorOpen}
                    onOpenChange={setEditorOpen}
                    sourceFile={beautifyFile}
                    editTarget={editTarget}
                    screenshot={screenshot}
                />
```

- [ ] **Step 4: Verify the whole frontend gate**

Run: `bun run lint:check && bun run format:check && bun run types:check && bun run test`
Expected: PASS. (If `ComposerToolbar`/`MediaChips` are referenced in `resources/js/components/compose/__tests__`, update any prop expectations there.)

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/compose/composer-toolbar.tsx resources/js/components/compose/media-chips.tsx resources/js/components/compose/composer.tsx resources/js/components/compose/__tests__
git commit -m "feat(screenshot): wire Beautify + re-edit entry points into the composer"
```

---

## Final verification

- [ ] **Backend suite**

Run: `php artisan test --compact --filter="PostMedia|MediaStorageService|MediaApi|ScreenshotApi|PostView"`
Expected: PASS.

- [ ] **Frontend gate**

Run: `bun run lint:check && bun run format:check && bun run types:check && bun run test`
Expected: PASS.

- [ ] **Manual eyeball (user runs the app)**

Verify: Beautify button opens the editor on a picked screenshot; gradients/padding/radius/shadow/aspect/tilt update the preview live; crop mode lets you drag a region; Apply attaches the composed image; re-opening via the chip's Edit pencil restores all settings and re-composes from the clean source; the composed image publishes normally.

---

## Self-Review Notes (addressed)

- **Spec coverage:** gradient backgrounds (Task 4), padding/radius/shadow/aspect/tilt (Tasks 5/8/11), crop freeform + ratio-lock (Tasks 6/9), composed-replaces-media + persisted source/settings (Tasks 1-3, 10), re-edit entry point (Task 12), `BackgroundFill` seam for future sources (Task 4), Vitest pure-logic tests + Pest feature tests (Tasks 1-7), no-backend-change-to-video preserved (Task 1 keeps video media valid via `toView`).
- **Deviation from spec:** crop overlay ships 4 corner handles + body move (not 8 handles) for v1 — still freeform with ratio-lock; flagged in Task 9. Confirm acceptable with the user.
- **Type consistency:** `EditSettings`, `CropRect`, `BackgroundFill`, `AspectPreset`, `Corner` names are used identically across tasks; `toView()` shape matches the extended `MediaView`.
- **Open implementation confirmations (flagged inline, resolved by `tsc`/at the file):** Wayfinder `update({post,media})` arg shape (Task 10); presence of `Dialog`/`Slider` UI primitives (Task 11); fixed-aspect stage sizing visual (Tasks 8/11).
