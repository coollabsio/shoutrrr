<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\PostMedia;
use Illuminate\Http\UploadedFile;

class MediaStorageService
{
    /**
     * Store an uploaded image on the public disk and create an orphan PostMedia row.
     */
    public function store(string $workspaceId, UploadedFile $file, ?string $altText = null): PostMedia
    {
        $disk = 'public';
        $path = $file->store('media/'.$workspaceId, $disk);

        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }
}
