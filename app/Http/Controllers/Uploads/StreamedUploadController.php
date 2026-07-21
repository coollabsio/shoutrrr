<?php

declare(strict_types=1);

namespace App\Http\Controllers\Uploads;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;

class StreamedUploadController extends Controller
{
    /**
     * Receive a signed direct-to-disk video upload by STREAMING the request body
     * to storage. Laravel's framework receiver (Illuminate\Filesystem\ReceiveFile)
     * buffers the whole body into a PHP string via getContent(), so a video near
     * the 1 GiB platform ceiling would need >1 GiB of worker memory. Copying
     * php://input to the disk in bounded chunks keeps peak memory flat regardless
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

        // Reject early when the client honestly declares an over-ceiling size.
        if ((int) $request->header('Content-Length') > $ceiling) {
            abort(413, 'Video exceeds the maximum allowed size.');
        }

        // PHP does NOT enforce post_max_size on a raw PUT body, and Content-Length
        // can be forged or omitted (chunked transfer). Copy at most `ceiling + 1`
        // bytes into a memory-bounded buffer (php://temp spills to a temp file past
        // 8 MiB) so a hostile or buggy client can never fill the disk; if the copy
        // reaches ceiling + 1, the source is over the limit and nothing is stored.
        $buffer = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'r+b');
        if ($buffer === false) {
            abort(500, 'Could not buffer the upload.');
        }

        $source = $request->getContent(asResource: true);

        try {
            $copied = stream_copy_to_stream($source, $buffer, $ceiling + 1);

            if ($copied === false || $copied > $ceiling) {
                abort(413, 'Video exceeds the maximum allowed size.');
            }

            rewind($buffer);
            Storage::disk($disk)->writeStream($path, $buffer);
        } catch (PathTraversalDetected) {
            abort(404);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            fclose($buffer);
        }

        return response()->noContent();
    }
}
