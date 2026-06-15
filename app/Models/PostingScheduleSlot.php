<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostingScheduleSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $posting_schedule_id
 * @property int $weekday
 * @property int $hour
 * @property int $minute
 * @property int $position
 */
#[Fillable([
    'posting_schedule_id',
    'weekday',
    'hour',
    'minute',
    'position',
])]
class PostingScheduleSlot extends Model
{
    /** @use HasFactory<PostingScheduleSlotFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'hour' => 'integer',
            'minute' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PostingSchedule, $this>
     */
    public function postingSchedule(): BelongsTo
    {
        return $this->belongsTo(PostingSchedule::class);
    }
}
