<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostingScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $timezone
 */
#[Fillable([
    'workspace_id',
    'timezone',
])]
class PostingSchedule extends Model
{
    /** @use HasFactory<PostingScheduleFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<PostingScheduleSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(PostingScheduleSlot::class)
            ->orderBy('weekday')
            ->orderBy('hour')
            ->orderBy('minute');
    }
}
