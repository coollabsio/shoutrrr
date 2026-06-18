<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\SignVideoUploadRequest;
use App\Http\Requests\Post\StoreVideoRequest;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostVideoUploadController extends Controller
{
    /**
     * Generate a presigned upload URL for a video file.
     * The storage key is generated server-side — never trusted from the client.
     */
    public function url(SignVideoUploadRequest $request, Post $post): JsonResponse
    {
        $disk = config('media.disk');
        $key = 'tmp/media/'.$post->workspace_id.'/'.Str::uuid().'.mp4';

        ['url' => $uploadUrl, 'headers' => $headers] = Storage::disk($disk)->temporaryUploadUrl(
            $key,
            now()->addMinutes(15),
        );

        return response()->json([
            'key' => $key,
            'url' => $uploadUrl,
            'headers' => $headers,
        ]);
    }

    /**
     * Confirm an upload: validate the key prefix, check the object exists, move it to permanent
     * storage, and create a PostMedia record.
     */
    public function store(StoreVideoRequest $request, Post $post): JsonResponse
    {
        $validated = $request->validated();
        $key = $validated['key'];
        $disk = config('media.disk');

        // Security: reject any key that is not within tmp/media/{workspace_id}/<uuid>.mp4
        // This prevents clients from finalizing objects in other workspaces or bypassing the
        // tmp prefix entirely.
        $this->authorizeKey($key, (string) $post->workspace_id);

        abort_unless(Storage::disk($disk)->exists($key), 422, 'Upload not found.');

        $size = (int) Storage::disk($disk)->size($key);

        if ($size > Platform::maxVideoBytesCeiling()) {
            Storage::disk($disk)->delete($key);
            abort(422, 'Video exceeds the maximum allowed size.');
        }

        $final = 'media/'.$post->workspace_id.'/'.Str::uuid().'.mp4';
        Storage::disk($disk)->move($key, $final);

        $media = PostMedia::create([
            'workspace_id' => $post->workspace_id,
            'post_id' => null,
            'disk' => $disk,
            'path' => $final,
            'kind' => 'video',
            'mime' => 'video/mp4',
            'size_bytes' => $size,
            'width' => (int) $validated['width'],
            'height' => (int) $validated['height'],
            'duration_seconds' => (int) $validated['duration_seconds'],
            'alt_text' => $validated['alt_text'] ?? null,
            'position' => 0,
        ]);

        return response()->json(['media' => [
            'id' => $media->id,
            'url' => $media->url(),
            'mime' => $media->mime,
            'kind' => $media->kind,
            'duration_seconds' => $media->duration_seconds,
            'alt_text' => $media->alt_text,
        ]], 201);
    }

    /**
     * Validate that a client-supplied key exactly matches `tmp/media/{workspaceId}/{uuid}.mp4`.
     * Aborts with 422 if the key is outside the workspace's tmp prefix.
     */
    private function authorizeKey(string $key, string $workspaceId): void
    {
        $prefix = 'tmp/media/'.$workspaceId.'/';

        // Must start with the exact workspace prefix — no path traversal allowed.
        if (! str_starts_with($key, $prefix)) {
            abort(422, 'Invalid upload key.');
        }

        $remainder = substr($key, strlen($prefix));

        // Remainder must be exactly: <uuid>.mp4 (no subdirectories, no "..", no extra segments)
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.mp4$/i', $remainder)) {
            abort(422, 'Invalid upload key.');
        }
    }
}
