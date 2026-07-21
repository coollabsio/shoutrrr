<?php

declare(strict_types=1);

namespace App\Http\Controllers\Uploads;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;

class StreamedUploadController extends Controller
{
    /**
     * Receive a signed direct-to-disk video upload by STREAMING the request body
     * to storage. Laravel's framework receiver (Illuminate\Filesystem\ReceiveFile)
     * buffers the whole body into a PHP string via getContent(), so a video near
     * the 1 GiB platform ceiling would need >1 GiB of worker memory. Copying
     * php://input to the disk in small chunks keeps peak memory flat regardless
     * of file size — the only viable approach on a local disk.
     *
     * Object-storage disks never reach this route: they presign a direct PUT and
     * the bytes never pass through the app (see FileStorage::temporaryVideoUploadUrl).
     *
     * The route is gated by a relative temporary signature (see bootstrap/app.php),
     * so the signed path is trusted here exactly as the framework handler trusts it.
     */
    public function __invoke(Request $request, string $path): Response
    {
        $disk = FileStorage::diskName();
        $ceiling = Platform::maxVideoBytesCeiling();

        // Reject an over-ceiling upload before reading a byte when the client
        // declares its size. (post_max_size is the hard PHP-level gate; this
        // turns it into a clean, explicit 413.)
        if ((int) $request->header('Content-Length') > $ceiling) {
            abort(413, 'Video exceeds the maximum allowed size.');
        }

        $stream = $request->getContent(asResource: true);

        try {
            Storage::disk($disk)->writeStream($path, $stream);
        } catch (PathTraversalDetected) {
            abort(404);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // A client that omits or lies about Content-Length can still stream up to
        // post_max_size; enforce the real ceiling against the stored size and drop
        // anything over it rather than leaving it for the confirm step to reject.
        if ((int) Storage::disk($disk)->size($path) > $ceiling) {
            Storage::disk($disk)->delete($path);
            Log::warning('Rejected oversize streamed video upload', ['path' => $path]);
            abort(413, 'Video exceeds the maximum allowed size.');
        }

        return response()->noContent();
    }
}
