<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $post_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $position
 */
#[Fillable([
    'workspace_id',
    'post_id',
    'disk',
    'path',
    'mime',
    'size_bytes',
    'width',
    'height',
    'alt_text',
    'position',
])]
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    protected $table = 'post_media';

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
