<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StoresPresignedVideo;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use RuntimeException;

class MediaController extends Controller
{
    use StoresPresignedVideo;

    public function store(Request $request, MediaStorageService $media): JsonResponse
    {
        $this->authorize('create', Post::class);

        $validated = $request->validate([
            'file' => ['required_without:url', 'file', 'image', 'max:8192'],
            'url' => ['required_without:file', 'url'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $workspaceId = (string) Context::get('workspace_id');
        $alt = $validated['alt_text'] ?? null;

        try {
            $stored = $request->hasFile('file')
                ? $media->store($workspaceId, $request->file('file'), $alt)
                : $media->storeFromUrl($workspaceId, $validated['url'], $alt);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $this->createdMedia($stored);
    }

    /**
     * Request a video upload URL.
     *
     * Step 1 of the video upload: returns a short-lived (15 min) signed URL and a
     * server-generated `key`. `PUT` the raw MP4 bytes to `url` with the returned
     * `headers`, then register the upload with `POST /media/video`.
     */
    public function videoUploadUrl(Request $request): JsonResponse
    {
        $this->authorize('create', Post::class);

        $request->validate([
            'content_type' => ['required', 'string', 'in:video/mp4'],
        ]);

        $target = $this->videoUploadTarget((string) Context::get('workspace_id'));

        // On a local disk the signed URL is relative to the instance; API clients
        // are not on the page's origin, so hand them an absolute one.
        return response()->json([...$target, 'url' => url($target['url'])]);
    }

    /**
     * Register an uploaded video.
     *
     * Step 2 of the video upload: confirms the object `PUT` to the signed URL,
     * checks it is an MP4 under the size ceiling, and adds it to the media
     * library. Returns the same shape as `POST /media`.
     */
    public function storeVideo(Request $request): JsonResponse
    {
        $this->authorize('create', Post::class);

        $validated = $request->validate([
            'key' => ['required', 'string'],
            'duration_seconds' => ['required', 'integer', 'min:1'],
            'width' => ['required', 'integer', 'min:1'],
            'height' => ['required', 'integer', 'min:1'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $workspaceId = (string) Context::get('workspace_id');
        $stored = $this->persistUploadedVideo($validated, $workspaceId, ['workspace_id' => $workspaceId, 'via' => 'api']);

        return $this->createdMedia($stored);
    }

    public function destroy(string $mediaId): JsonResponse
    {
        $model = PostMedia::query()->whereKey($mediaId)
            ->firstOr(fn () => abort(404, 'No media with that id exists in this workspace.'));

        $model->delete();

        return response()->json(['deleted' => true, 'id' => $mediaId]);
    }

    private function createdMedia(PostMedia $media): JsonResponse
    {
        return response()->json([
            'id' => $media->id,
            'mime' => $media->mime,
            'width' => $media->width,
            'height' => $media->height,
            'alt_text' => $media->alt_text,
        ], 201);
    }
}
