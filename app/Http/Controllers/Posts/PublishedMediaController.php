<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use App\Services\Media\DerivedMedia;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublishedMediaController extends Controller
{
    /**
     * Stream a time-limited provider-facing media URL through the application.
     *
     * Object stores commonly sign GET and HEAD requests differently. Providers
     * such as Google validate source URLs with HEAD before fetching the bytes,
     * so handing them an object-store GET URL produces a misleading 403. This
     * signed application URL supports both methods while keeping the bucket
     * private for every self-hosted storage backend.
     */
    public function show(Request $request, PostMedia $publicMedia): StreamedResponse
    {
        $path = $request->string('path')->toString();
        $allowedPaths = [$publicMedia->path, ...DerivedMedia::pathsFor($publicMedia)];

        if (! in_array($path, $allowedPaths, true)) {
            abort(404);
        }

        $storage = FileStorage::disk($publicMedia->disk);
        abort_unless($storage->exists($path), 404);

        return $storage->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => $path === $publicMedia->path ? $publicMedia->mime : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
