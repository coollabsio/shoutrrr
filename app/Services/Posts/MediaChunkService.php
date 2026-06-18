<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\PostMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException; // used by append() on out-of-order chunks

class MediaChunkService
{
    /**
     * Append one chunk to the upload's temp file and return the running byte size.
     * index 0 starts (or restarts) the file; later indices append in order.
     */
    public function append(string $workspaceId, string $uploadId, int $index, UploadedFile $chunk): int
    {
        $local = Storage::disk('local');
        $relative = $this->partPath($workspaceId, $uploadId);

        if ($index === 0) {
            $local->put($relative, '');
        }

        if (! $local->exists($relative)) {
            throw new RuntimeException('Chunk upload sequence started out of order.');
        }

        $full = $local->path($relative);
        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($full, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return (int) filesize($full);
    }

    /**
     * @param  array{duration_seconds: int, width: int, height: int, alt_text: ?string}  $meta
     */
    public function finalize(string $workspaceId, string $uploadId, array $meta): PostMedia
    {
        $local = Storage::disk('local');
        $relative = $this->partPath($workspaceId, $uploadId);
        $full = $local->path($relative);

        $path = 'media/'.$workspaceId.'/'.Str::uuid()->toString().'.mp4';
        Storage::disk('public')->writeStream($path, fopen($full, 'rb'));
        $size = (int) Storage::disk('public')->size($path);
        $local->delete($relative);

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => 'public',
            'path' => $path,
            'kind' => 'video',
            'mime' => 'video/mp4',
            'size_bytes' => $size,
            'width' => $meta['width'],
            'height' => $meta['height'],
            'duration_seconds' => $meta['duration_seconds'],
            'alt_text' => $meta['alt_text'],
            'position' => 0,
        ]);
    }

    private function partPath(string $workspaceId, string $uploadId): string
    {
        return 'media-chunks/'.$workspaceId.'/'.$uploadId.'.part';
    }
}
