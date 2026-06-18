<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PostTargetMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $post_target_id
 * @property CarbonImmutable $captured_at
 * @property int $likes
 * @property int $comments
 * @property int $reposts
 * @property int|null $impressions
 */
#[Fillable(['post_target_id', 'captured_at', 'likes', 'comments', 'reposts', 'impressions'])]
class PostTargetMetric extends Model
{
    /** @use HasFactory<PostTargetMetricFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'likes' => 'integer',
            'comments' => 'integer',
            'reposts' => 'integer',
            'impressions' => 'integer',
        ];
    }

    /** @return BelongsTo<PostTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
