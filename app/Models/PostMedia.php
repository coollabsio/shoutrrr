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
 * @property string $kind
 * @property int|null $duration_seconds
 * @property string|null $source_disk
 * @property string|null $source_path
 * @property array<string, mixed>|null $edit_settings
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
    'kind',
    'duration_seconds',
    'source_disk',
    'source_path',
    'edit_settings',
])]
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    protected $table = 'post_media';

    /**
     * In-memory default so a row freshly created without an explicit kind still
     * serializes as an image (Eloquent does not hydrate DB defaults after create).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'image'];

    /**
     * Delete the backing file(s) when the row is removed, so storage doesn't
     * accumulate orphans. Covers both the composed file and a retained source.
     */
    protected static function booted(): void
    {
        static::deleting(function (PostMedia $media): void {
            Storage::disk($media->disk)->delete($media->path);

            if ($media->source_path !== null) {
                Storage::disk($media->source_disk ?? $media->disk)->delete($media->source_path);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['edit_settings' => 'array'];
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function source_url(): ?string
    {
        if ($this->source_path === null) {
            return null;
        }

        return Storage::disk($this->source_disk ?? $this->disk)->url($this->source_path);
    }

    /**
     * @return array{id: string, url: string, mime: string, kind: string, duration_seconds: int|null, alt_text: string|null, position: int, edit_settings: array<string, mixed>|null, source_url: string|null}
     */
    public function toView(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'mime' => $this->mime,
            'kind' => $this->kind,
            'duration_seconds' => $this->duration_seconds,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
            'edit_settings' => $this->edit_settings,
            'source_url' => $this->source_url(),
        ];
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }
}
