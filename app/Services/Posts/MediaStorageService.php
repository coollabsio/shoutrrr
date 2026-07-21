<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\PostMedia;
use App\Support\FileStorage;
use App\Support\SafeImageFetcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class MediaStorageService
{
    public function __construct(private readonly SafeImageFetcher $fetcher) {}

    /**
     * Store an uploaded image on the configured disk and create an orphan PostMedia row.
     */
    public function store(string $workspaceId, UploadedFile $file, ?string $altText = null): PostMedia
    {
        $disk = FileStorage::diskName();
        $path = $file->store('media/'.$workspaceId, $disk);

        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'kind' => 'image',
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }

    /**
     * Download an image from a public URL (SSRF-guarded) and store it as an orphan
     * PostMedia row, mirroring store().
     *
     * @throws RuntimeException if the URL is blocked or the response is not a valid image.
     */
    public function storeFromUrl(string $workspaceId, string $url, ?string $altText = null): PostMedia
    {
        $image = $this->fetcher->fetch($url);

        $extension = match ($image['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };

        $disk = FileStorage::diskName();
        $path = 'media/'.$workspaceId.'/'.Str::uuid()->toString().'.'.$extension;
        FileStorage::disk($disk)->put($path, $image['bytes']);

        $dimensions = @getimagesizefromstring($image['bytes']) ?: [null, null];

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'kind' => 'image',
            'mime' => $image['mime'],
            'size_bytes' => strlen($image['bytes']),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }

    /**
     * Store a beautified image: the composed image becomes the post's media,
     * the original source is retained for non-destructive re-editing.
     *
     * @param  array<string, mixed>  $settings
     */
    public function storeBeautified(string $workspaceId, UploadedFile $composed, UploadedFile $source, array $settings, ?string $altText = null): PostMedia
    {
        $disk = FileStorage::diskName();
        $path = $composed->store('media/'.$workspaceId, $disk);
        $sourcePath = $source->store('media/'.$workspaceId, $disk);

        $dimensions = @getimagesize($composed->getRealPath()) ?: [null, null];

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'kind' => 'image',
            'source_disk' => $disk,
            'source_path' => $sourcePath,
            'edit_settings' => $settings,
            'mime' => (string) $composed->getMimeType(),
            'size_bytes' => $composed->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }

    /**
     * Replace the composed file + settings of an existing beautified media, keeping its source.
     *
     * @param  array<string, mixed>  $settings
     */
    public function replaceBeautified(PostMedia $media, UploadedFile $composed, array $settings, ?string $altText = null): PostMedia
    {
        // Store the new file and commit the row before deleting the old file, so a
        // failed store never leaves the row pointing at a now-missing path.
        $oldPath = $media->path;
        $path = $composed->store('media/'.$media->workspace_id, $media->disk);
        $dimensions = @getimagesize($composed->getRealPath()) ?: [null, null];

        $media->update([
            'path' => $path,
            'edit_settings' => $settings,
            'mime' => (string) $composed->getMimeType(),
            'size_bytes' => $composed->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
        ]);

        if ($oldPath !== $path) {
            FileStorage::disk($media->disk)->delete($oldPath);
        }

        return $media->refresh();
    }
}
