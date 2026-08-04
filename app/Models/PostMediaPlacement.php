<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostMediaPlacementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $post_target_id
 * @property string $post_media_id
 * @property string $segment_ref
 * @property int $position
 */
#[Fillable([
    'post_target_id',
    'post_media_id',
    'segment_ref',
    'position',
])]
class PostMediaPlacement extends Model
{
    /** @use HasFactory<PostMediaPlacementFactory> */
    use HasFactory, HasUuids;

    protected $table = 'post_media_placements';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    /**
     * @return BelongsTo<PostMedia, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(PostMedia::class, 'post_media_id');
    }
}
