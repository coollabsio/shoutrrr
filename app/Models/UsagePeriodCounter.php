<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UsagePeriodCounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $period_start
 * @property string $period_end
 * @property string $category
 * @property string $platform
 * @property string $operation
 * @property int $event_count
 * @property int $total_quota
 */
#[Fillable(['workspace_id', 'period_start', 'period_end', 'category', 'platform', 'operation', 'event_count', 'total_quota'])]
class UsagePeriodCounter extends Model
{
    /** @use HasFactory<UsagePeriodCounterFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        // period_start/period_end are stored and compared as plain 'Y-m-d' strings —
        // the exact format UsageRecorder writes via insertOrIgnore (which bypasses
        // casts). A datetime cast would round-trip them as 'Y-m-d 00:00:00' on
        // model writes, which would never match recorder-written rows and would
        // spawn duplicate counter rows. So they are intentionally left uncast.
        return [
            'event_count' => 'integer',
            'total_quota' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
