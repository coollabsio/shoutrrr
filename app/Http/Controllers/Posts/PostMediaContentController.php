<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PostMediaContentController extends Controller
{
    /**
     * Stream a stored media file back through the app on its own origin.
     *
     * In production the bytes live on an S3-compatible bucket that serves them
     * via presigned URLs with no CORS headers. That is fine for plain <img>/<video>
     * display, but the client-side image/video editors must `fetch()` the source
     * into a canvas — a cross-origin request the bucket rejects. Proxying the read
     * here gives the editor a same-origin URL, sidestepping bucket CORS entirely.
     *
     * `variant=source` serves the retained pre-edit original (present only on
     * beautified media); the default serves the composed/main file.
     */
    public function show(Request $request, PostMedia $media): StreamedResponse
    {
        if ($request->query('variant') === 'source') {
            abort_if($media->source_path === null, 404);
            $disk = $media->source_disk ?? $media->disk;
            $path = $media->source_path;
        } else {
            $disk = $media->disk;
            $path = $media->path;
        }

        $storage = FileStorage::disk($disk);
        abort_unless($storage->exists($path), 404);

        return $storage->response($path, null, [
            // Authenticated per-user response — never store in a shared cache.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
